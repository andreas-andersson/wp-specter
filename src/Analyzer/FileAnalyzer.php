<?php

declare(strict_types=1);

namespace WpSpecter\Analyzer;

use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\Parser\PhpTokenParser;

/**
 * Flags PHP files that are never include()/require()'d, never referenced via
 * get_template_part()/get_header()/get_footer()/get_sidebar(), and never referenced from a
 * block.json "render" field — anywhere in the scanned project.
 *
 * Root-level theme/plugin files and files under known template directories are left to
 * TemplateAnalyzer, which already understands the WP template hierarchy. This analyzer covers
 * everything else: inc/, classes/, includes/, admin/, api/, and similar support directories.
 */
final class FileAnalyzer
{
    // "resources/views" is Roots Sage/Acorn's Blade views root — same hand-off-to-TemplateAnalyzer
    // reasoning as the other three, it's just a framework-specific convention rather than a WP one.
    private const TEMPLATE_DIRS = ['templates', 'template-parts', 'parts', 'resources/views'];
    private const BLOCK_JSON_RENDER_KEYS = ['render', 'renderCallback'];
    // Composer autoload sections that map namespaces/paths to a class loader rather than a
    // literal include() — "autoload-dev" covers test-support classes under the same scheme.
    private const COMPOSER_AUTOLOAD_KEYS = ['autoload', 'autoload-dev'];

    /**
     * @var list<string> Absolute dirs (trailing slash) mapped by a psr-4/psr-0/classmap dir entry
     *   that resolves into $rootDir/vendor/ — genuine third-party dependency code, out of scope
     *   for this analyzer's "is this file used" question the same way the rest of vendor/ is, so
     *   exempted wholesale rather than checked against $referencedClassNames.
     */
    private array $vendorAutoloadDirs = [];
    /** @var list<string> Absolute files mapped by a vendor-side classmap entry — same reasoning. */
    private array $vendorAutoloadFiles = [];
    /**
     * @var list<string> Absolute files a composer.json/generated "files" autoload entry always
     *   `require`s unconditionally at Composer's own bootstrap time, vendor or not — genuinely
     *   always used the moment Composer's autoloader itself runs, not something a class reference
     *   could ever prove or disprove.
     */
    private array $alwaysLoadedFiles = [];
    /**
     * @var list<string> Absolute dirs (trailing slash) mapped by a psr-4/psr-0 entry that resolves
     *   OUTSIDE $rootDir/vendor/ — the scanned project's own source, just laid out for Composer's
     *   class loader instead of a literal include()/require(). Being autoloadABLE doesn't prove a
     *   file is actually used (see isProjectAutoloadedClassUsed) — unlike vendor/ dependencies,
     *   this is exactly the code this analyzer exists to find dead code in, so it's not exempted
     *   wholesale the way $vendorAutoloadDirs is.
     */
    private array $projectAutoloadDirs = [];
    /** @var array<string,string> Absolute file => the exact class name a project-own classmap entry maps it to. */
    private array $projectAutoloadClassFiles = [];
    /**
     * @var array<string,true> Every class/interface/trait short name referenced anywhere in the
     *   project via `new`/`instanceof`/`extends`/`implements`/a type hint/a static call receiver —
     *   whatever PhpTokenParser's own $classReferences already tracks (see ClassAnalyzer's
     *   findUnusedClasses, which builds the same kind of set for the same reason). Consulted by
     *   isProjectAutoloadedClassUsed() so a PSR-4/classmap-mapped project file is only exempted
     *   from candidacy when something actually appears to use the class it declares — not merely
     *   because Composer *could* load it.
     */
    private array $referencedClassNames = [];
    /**
     * @var list<string> Project-relative dirs exempted because every file inside them is only
     *   ever reached through a `foreach (glob(...) as $f) { require $f; }`-style bulk-include no
     *   per-file include()/require() reference can catch — unconditional, not lazy, so blanket
     *   exemption is correct here.
     */
    private array $dynamicLoadExemptDirs = [];
    /**
     * @var list<string> Project-relative dirs exempted because a file inside them is only
     *   reachable through a hand-rolled spl_autoload_register() autoloader. Kept as its own list
     *   (rather than folded into $dynamicLoadExemptDirs) as a marker of a real-usage-proof
     *   extension that was tried and reverted here — see isCandidate()'s own comment on this
     *   check for why basename-as-class-name (sound for Composer's PSR-4, see
     *   isProjectAutoloadedClassUsed) doesn't hold for a hand-rolled autoloader in general.
     */
    private array $autoloadRegisterExemptDirs = [];
    /**
     * @var array<string,true> Absolute file => true, for a file whose every top-level class is
     *   already exempted whole-cloth by ClassAnalyzer::isFullyExemptClass() (e.g. Roots Acorn's
     *   View\Composer — discovered by Acorn's own filesystem convention, never referenced by
     *   name anywhere in project code). ClassAnalyzer already gets this exactly right at the
     *   class level; this analyzer previously had no equivalent at the file level, so a file
     *   containing nothing but one of these classes was still flagged UnusedFile even when
     *   `--type=classes` correctly showed it as used. See loadFullyExemptFiles().
     */
    private array $fullyExemptFiles = [];

    public function __construct(private readonly PhpTokenParser $parser) {}

    /**
     * @param list<string> $files
     * @param (callable(int, int): void)|null $onProgress See PhpTokenParser::parseAll().
     * @return list<Finding>
     */
    public function analyze(array $files, string $rootDir, ?callable $onProgress = null): array
    {
        $parseResults = $this->parser->parseAll($files, $onProgress);

        $referenced = $this->buildReferencedIndex($parseResults, $rootDir);

        $rootDir = rtrim($rootDir, '/');
        $this->loadComposerAutoloadPaths($rootDir);
        $this->loadDynamicLoadExemptDirs($parseResults, $rootDir);
        $this->loadReferencedClassNames($parseResults);
        $this->loadFullyExemptFiles($parseResults);
        $findings = [];

        foreach ($files as $file) {
            if (!$this->isCandidate($file, $rootDir) || isset($this->fullyExemptFiles[$file])) {
                continue;
            }

            $relative = $this->normalizePath(ltrim(str_replace($rootDir, '', $file), '/'));
            $basename = pathinfo($file, PATHINFO_FILENAME);

            if (
                isset($referenced[$relative])
                || isset($referenced[$basename])
                || $this->isReferencedByPartialMatch($relative, $referenced)
            ) {
                continue;
            }

            $findings[] = new Finding(
                type: FindingType::UnusedFile,
                name: basename($file),
                file: $file,
                line: 1,
                certainty: FindingCertainty::Warning,
                note: 'not included, required, or referenced anywhere in scanned directory',
            );
        }

        usort($findings, fn(Finding $a, Finding $b) => $a->file <=> $b->file);

        return $findings;
    }

    private function isCandidate(string $file, string $rootDir): bool
    {
        if (!str_ends_with($file, '.php')) {
            return false;
        }

        // In a multi-target (composer project) scan, $files spans every target so reference
        // resolution can see across them — but a file that belongs to a *different* target's
        // tree is never a candidate for "unused" here; its own target's analyze() call covers it.
        if (!str_starts_with($file, $rootDir . '/')) {
            return false;
        }

        // Root-level files (functions.php, index.php, single.php, ...) are the WP template
        // hierarchy — TemplateAnalyzer already covers them.
        if (dirname($file) === $rootDir) {
            return false;
        }

        // index.php is used both as a directory-listing blocker ("silence is golden") and as a
        // sibling-file autoloader — neither pattern is a meaningful "unused" signal.
        if (basename($file) === 'index.php') {
            return false;
        }

        // A file only ever loaded by Composer's autoloader as a genuine THIRD-PARTY dependency
        // (vendor/, or a composer "files" entry Composer's own bootstrap always runs
        // unconditionally) has no include()/require() call anywhere to find — it's reachable,
        // just not through anything this analyzer's referenced-index can see, and auditing a
        // dependency's own internal dead code is out of scope for a scan of the project that
        // merely depends on it. Exempted wholesale.
        if ($this->isComposerAutoloaded($file)) {
            return false;
        }

        // The scanned project's OWN source, just laid out for Composer's class loader instead of
        // a literal include()/require() — being autoloadABLE doesn't mean the class is actually
        // used (see isProjectAutoloadedClassUsed's own docblock), so unlike the vendor/ case
        // above this doesn't exempt wholesale; it only exempts once the class it declares
        // actually looks referenced somewhere in the project.
        if ($this->isUnderProjectAutoloadMapping($file)) {
            if ($this->isProjectAutoloadedClassUsed($file)) {
                return false;
            }
        }

        // WP Page Templates are selected from the admin UI via this header comment — WP loads
        // them by scanning the theme, never through a visible include()/require() call.
        if ($this->hasPageTemplateHeader($file)) {
            return false;
        }

        $relative = ltrim(str_replace($rootDir, '', $file), '/');

        // Block patterns: WP core (6.0+) auto-registers every PHP file directly under a theme's
        // own "patterns/" directory that carries a valid pattern-file header (minimum "Title:"),
        // purely by directory-scan convention — same "loaded by header comment scan, never by a
        // visible require()" shape as Page Templates above, just a different header field and a
        // directory-scoped check (unlike "Template Name:", a bare "Title:" docblock line isn't
        // distinctive enough to trust outside patterns/ — plenty of unrelated files have doc
        // comments with a "Title:" field for their own reasons). Confirmed against wordpress.org's
        // top themes: every current PHP file under patterns/ in Twenty Twenty-Three/Four/Five and
        // Extendable was otherwise reported as 100% of that theme's "unused files" — the entire
        // finding category was this one gap.
        if (str_starts_with($relative, 'patterns/') && $this->hasPatternFileHeader($file)) {
            return false;
        }

        foreach (self::TEMPLATE_DIRS as $dir) {
            if (str_starts_with($relative, $dir . '/')) {
                return false;
            }
        }

        // A directory only ever bulk-loaded through `foreach (glob(...) as $f) { require $f; }`,
        // or reached through a hand-rolled spl_autoload_register() autoloader, has no per-file
        // include()/require() call for the referenced-index to find. Exempted wholesale, same
        // shape as the vendor-dependency Composer exemption above — real-usage-proof (basename
        // assumed to be the class name, see isProjectAutoloadedClassUsed) was tried here too and
        // reverted: unlike Composer's PSR-4, "filename equals class name" isn't a rule a
        // hand-rolled autoloader is bound by. Real-world regression (wp-smushit):
        // `app/class-admin.php` declares `class Admin` — WP's own long-standing `class-{slug}.php`
        // naming convention, which a spl_autoload_register callback commonly parses by stripping
        // the `class-` prefix — basename-as-class-name doesn't hold, so every file under such an
        // autoloader's tree false-positived at once (0 → 221 findings project-wide).
        if ($this->isUnderDynamicLoadExemptDir($relative)) {
            return false;
        }

        if ($this->isUnderAutoloadRegisterExemptDir($relative)) {
            return false;
        }

        return true;
    }

    private function isUnderDynamicLoadExemptDir(string $relative): bool
    {
        foreach ($this->dynamicLoadExemptDirs as $dir) {
            // An empty $dir means the triggering glob-loop caller lives at the project root —
            // every relative path is "under" the project root, but relativeDir() returns ''
            // rather than '.' for that case (see its own
            // doc), so the usual "$dir . '/'" prefix check would never match anything and
            // silently fail to exempt a single file for a root-level autoloader.
            if ($dir === '' || $relative === $dir || str_starts_with($relative, $dir . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reads $rootDir/composer.json's "autoload"/"autoload-dev" blocks and records every
     * path they map, so isComposerAutoloaded() can exempt those files/dirs from candidacy.
     * A missing or malformed composer.json (no composer.json at all is the common case — most
     * scanned themes/plugins don't declare their own) just leaves both lists empty going into
     * loadGeneratedComposerAutoload() below, which is the one that actually matters whenever
     * vendor/ exists without its own composer.json.
     */
    private function loadComposerAutoloadPaths(string $rootDir): void
    {
        $this->vendorAutoloadDirs = [];
        $this->vendorAutoloadFiles = [];
        $this->alwaysLoadedFiles = [];
        $this->projectAutoloadDirs = [];
        $this->projectAutoloadClassFiles = [];

        $raw = @file_get_contents($rootDir . '/composer.json');
        $json = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($json)) {
            foreach (self::COMPOSER_AUTOLOAD_KEYS as $key) {
                if (isset($json[$key]) && is_array($json[$key])) {
                    $this->collectAutoloadSection($json[$key], $rootDir);
                }
            }
        }

        $this->loadGeneratedComposerAutoload($rootDir);
    }

    private function isVendorPath(string $path, string $rootDir): bool
    {
        return str_starts_with($path, $rootDir . '/vendor/');
    }

    /**
     * A physical vendor/ path isn't the only "this is really a dependency" signal: php-scoper/
     * Mozart/Strauss-style dependency prefixing relocates a package's classes into the host
     * project's own tree (WooCommerce's real-world shape: GraphQL, Symfony Polyfill, League,
     * Pelago, PSR/Container all vendored under `lib/packages`, well outside vendor/) while
     * keeping its own separate PSR-4 prefix namespace to avoid symbol collisions. Mozart's own
     * documented default convention for that prefix namespace is a literal `Vendor` segment
     * (`Automattic\WooCommerce\Vendor\` is exactly this) — narrow and convention-specific, but a
     * real, checkable signal, unlike trying to guess from the relocated directory name alone
     * (`lib/packages` gives no hint by itself).
     */
    private function isVendorPrefixedNamespace(string $prefix): bool
    {
        return (bool) preg_match('/(?:^|\\\\)Vendor\\\\/i', $prefix);
    }

    /**
     * composer.json only ever declares the SCANNED project's own top-level autoload rules —
     * a plugin that ships vendor/ but no composer.json of its own (WooCommerce's real-world
     * shape: composer.json is dev-only tooling, stripped from the release zip) has none of
     * those to read, yet every one of its dependencies' classes is still genuinely autoloaded.
     * Composer's own generated vendor/composer/autoload_*.php files are the actual, already-
     * fully-resolved source of truth for what's autoloaded — merged across the *whole*
     * dependency tree by Composer itself at dump-autoload time, and present whenever vendor/
     * exists at all, regardless of whether composer.json survived into the shipped copy.
     * Each one is a plain `return array(...)` computing two local path variables
     * ($vendorDir/$baseDir) from its own location and nothing else — safe to `include`
     * directly rather than needing a JSON parse or PhpTokenParser.
     */
    private function loadGeneratedComposerAutoload(string $rootDir): void
    {
        $composerDir = $rootDir . '/vendor/composer';

        foreach ($this->includeGeneratedAutoloadFile($composerDir . '/autoload_files.php') as $path) {
            if (is_string($path) && $path !== '') {
                // Always `require`d unconditionally the moment Composer's own autoloader runs —
                // true regardless of vendor-vs-project, see $alwaysLoadedFiles's own docblock.
                $this->alwaysLoadedFiles[] = $path;
            }
        }

        // autoload_classmap.php is already fully resolved by Composer's own scan: exact class
        // name => exact file, never a directory to further scan — precise enough to key
        // $projectAutoloadClassFiles by the real class name instead of falling back to basename.
        foreach ($this->includeGeneratedAutoloadFile($composerDir . '/autoload_classmap.php') as $className => $path) {
            if (!is_string($path) || $path === '' || !is_string($className)) {
                continue;
            }
            if ($this->isVendorPath($path, $rootDir)) {
                $this->vendorAutoloadFiles[] = $path;
            } else {
                $this->projectAutoloadClassFiles[$path] = $this->shortClassName($className);
            }
        }

        // autoload_psr4.php (PSR-4) and autoload_namespaces.php (legacy PSR-0) share the same
        // prefix => array-of-absolute-dirs shape.
        foreach (['autoload_psr4.php', 'autoload_namespaces.php'] as $filename) {
            foreach ($this->includeGeneratedAutoloadFile($composerDir . '/' . $filename) as $prefix => $dirs) {
                $isVendorPrefixed = is_string($prefix) && $this->isVendorPrefixedNamespace($prefix);
                foreach (is_array($dirs) ? $dirs : [$dirs] as $dir) {
                    if (!is_string($dir) || $dir === '') {
                        continue;
                    }
                    $dir = rtrim($dir, '/') . '/';
                    if ($isVendorPrefixed || $this->isVendorPath($dir, $rootDir)) {
                        $this->vendorAutoloadDirs[] = $dir;
                    } else {
                        $this->projectAutoloadDirs[] = $dir;
                    }
                }
            }
        }
    }

    private function shortClassName(string $className): string
    {
        $pos = strrpos($className, '\\');
        return $pos === false ? $className : substr($className, $pos + 1);
    }

    /** @return array<mixed> */
    private function includeGeneratedAutoloadFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        try {
            $result = include $path;
        } catch (\Throwable) {
            return [];
        }
        return is_array($result) ? $result : [];
    }

    /**
     * A package's own composer.json only ever declares ITS OWN source layout — a dependency's
     * autoload rules are never read from composer.json directly, only via the generated
     * vendor/composer/autoload_*.php files loadGeneratedComposerAutoload() handles above. So
     * every path collected here is, by construction, the scanned project's own code — except a
     * prefix matching isVendorPrefixedNamespace(), which still routes to
     * $vendorAutoloadDirs/$vendorAutoloadFiles the same as the generated-map case, since a
     * project can declare its own dependency-prefixing rules (Mozart/Strauss-style) in its own
     * composer.json just as easily as a generated file can carry them.
     *
     * @param array<string,mixed> $autoload
     */
    private function collectAutoloadSection(array $autoload, string $rootDir): void
    {
        foreach (['psr-4', 'psr-0'] as $key) {
            if (!isset($autoload[$key]) || !is_array($autoload[$key])) {
                continue;
            }
            foreach ($autoload[$key] as $prefix => $dirs) {
                $isVendorPrefixed = is_string($prefix) && $this->isVendorPrefixedNamespace($prefix);
                foreach (is_array($dirs) ? $dirs : [$dirs] as $dir) {
                    if (!is_string($dir) || $dir === '') {
                        // A root-namespace mapping ("Prefix\\": "") maps the whole project —
                        // too broad to exempt wholesale without defeating this check entirely.
                        continue;
                    }
                    $resolved = $this->joinPath($rootDir, $dir) . '/';
                    if ($isVendorPrefixed) {
                        $this->vendorAutoloadDirs[] = $resolved;
                    } else {
                        $this->projectAutoloadDirs[] = $resolved;
                    }
                }
            }
        }

        if (isset($autoload['classmap']) && is_array($autoload['classmap'])) {
            foreach ($autoload['classmap'] as $path) {
                if (!is_string($path) || $path === '') {
                    continue;
                }
                $resolved = $this->joinPath($rootDir, $path);
                if (is_dir($resolved)) {
                    $this->projectAutoloadDirs[] = $resolved . '/';
                } else {
                    // composer.json's own classmap entry names a path to scan, not a resolved
                    // class name the way the generated autoload_classmap.php key already is —
                    // basename is the same PSR-4-convention fallback $projectAutoloadDirs relies
                    // on for its own dir entries (see isProjectAutoloadedClassUsed).
                    $this->projectAutoloadClassFiles[$resolved] = pathinfo($resolved, PATHINFO_FILENAME);
                }
            }
        }

        if (isset($autoload['files']) && is_array($autoload['files'])) {
            foreach ($autoload['files'] as $path) {
                if (is_string($path) && $path !== '') {
                    $this->alwaysLoadedFiles[] = $this->joinPath($rootDir, $path);
                }
            }
        }
    }

    private function joinPath(string $rootDir, string $relative): string
    {
        return rtrim($rootDir, '/') . '/' . trim($relative, '/');
    }

    private function isComposerAutoloaded(string $file): bool
    {
        if (in_array($file, $this->alwaysLoadedFiles, true) || in_array($file, $this->vendorAutoloadFiles, true)) {
            return true;
        }
        foreach ($this->vendorAutoloadDirs as $dir) {
            if (str_starts_with($file, $dir)) {
                return true;
            }
        }
        return false;
    }

    private function isUnderProjectAutoloadMapping(string $file): bool
    {
        if (isset($this->projectAutoloadClassFiles[$file])) {
            return true;
        }
        foreach ($this->projectAutoloadDirs as $dir) {
            if (str_starts_with($file, $dir)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A PSR-4/classmap-mapped project file is only exempted from candidacy once something
     * actually appears to use the class it declares — being reachable via Composer's autoloader
     * doesn't prove that (see $projectAutoloadDirs's own docblock). A classmap entry already
     * names its exact class; a psr-4/psr-0 dir entry doesn't, so its class name is assumed to be
     * the file's own basename — the same assumption PSR-4 autoloading itself depends on (a class
     * autoloads at all only because its file is named after it).
     */
    private function isProjectAutoloadedClassUsed(string $file): bool
    {
        if (isset($this->projectAutoloadClassFiles[$file])) {
            return $this->isClassNameReferenced($this->projectAutoloadClassFiles[$file]);
        }
        foreach ($this->projectAutoloadDirs as $dir) {
            if (str_starts_with($file, $dir)) {
                return $this->isClassNameReferenced(pathinfo($file, PATHINFO_FILENAME));
            }
        }
        return false;
    }

    private function isClassNameReferenced(string $shortName): bool
    {
        return isset($this->referencedClassNames[$shortName]);
    }

    private function isUnderAutoloadRegisterExemptDir(string $relative): bool
    {
        foreach ($this->autoloadRegisterExemptDirs as $dir) {
            // Same empty-string-means-project-root reasoning as isUnderDynamicLoadExemptDir.
            if ($dir === '' || $relative === $dir || str_starts_with($relative, $dir . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<\WpSpecter\Parser\ParseResult> $parseResults
     */
    private function loadReferencedClassNames(array $parseResults): void
    {
        $this->referencedClassNames = [];
        foreach ($parseResults as $result) {
            foreach ($result->classReferences as $ref) {
                $this->referencedClassNames[$ref] = true;
            }
            // Same fallback ClassAnalyzer::findUnusedClasses relies on for its own "is this class
            // used" question: WP APIs that take a class name as a bare string and instantiate it
            // internally (register_panel_type(), a filter whose callback returns a class name,
            // WooCommerce's own REST controller registration in Init.php — an array of FQCNs
            // handed to a generic "instantiate each of these" loop) never produce a
            // new/instanceof/extends/implements/::/type-hint $classReferences entry. Real-world
            // regression this fixes: WooCommerce's MarketingCampaigns/MarketingCampaignTypes REST
            // controllers, registered exactly this way, otherwise false-positived the instant
            // isProjectAutoloadedClassUsed() started requiring real proof instead of a blanket
            // pass. Same imprecision trade-off as ClassAnalyzer's own version of this fallback:
            // any string literal anywhere that happens to match a real class's short name counts,
            // whether or not that particular literal was ever meant as a class reference.
            foreach ($result->functionCalls as $call) {
                $this->referencedClassNames[$call->name] = true;
            }
        }
    }

    /**
     * @param list<\WpSpecter\Parser\ParseResult> $parseResults
     */
    private function loadFullyExemptFiles(array $parseResults): void
    {
        $this->fullyExemptFiles = [];

        $classDefsByName = [];
        $classesByFile = [];
        foreach ($parseResults as $result) {
            foreach ($result->classDefs as $def) {
                $classDefsByName[$def->fqcn] = $def;
                $classesByFile[$def->file][] = $def;
            }
        }

        foreach ($classesByFile as $file => $defs) {
            // A file mixing an exempt class with something else (another class, a plain
            // function) isn't safe to exempt wholesale — only when EVERY class it declares is
            // one ClassAnalyzer would already treat as fully exempt does the file itself carry
            // no name a real usage reference could ever point at.
            $allExempt = true;
            foreach ($defs as $def) {
                if (!ClassAnalyzer::isFullyExemptClass($def->fqcn, $classDefsByName)) {
                    $allExempt = false;
                    break;
                }
            }
            if ($allExempt) {
                $this->fullyExemptFiles[$file] = true;
            }
        }
    }

    /**
     * @param list<\WpSpecter\Parser\ParseResult> $parseResults
     * $rootDir must already be rtrim'd — matches the convention isCandidate() relies on.
     */
    private function loadDynamicLoadExemptDirs(array $parseResults, string $rootDir): void
    {
        $this->dynamicLoadExemptDirs = [];
        $this->autoloadRegisterExemptDirs = [];

        // (ownerClass fqcn)::(methodName) => does that method's own body contain an
        // include/require keyword anywhere (see FunctionDef::$hasIncludeInBody). Built across
        // every scanned file before any PendingDirectoryLoaderCall is resolved against it,
        // since the callee method commonly lives in a different file than the call site — see
        // PendingDirectoryLoaderCall's own docblock (Flynt theme's FileLoader::loadPhpFiles()).
        $methodHasInclude = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionDefs as $def) {
                if ($def->isMethod && $def->ownerClass !== null && $def->hasIncludeInBody) {
                    $methodHasInclude[$def->ownerClass . '::' . $def->name] = true;
                }
            }
        }

        foreach ($parseResults as $result) {
            $callerRelDir = $this->relativeDir($result->file, $rootDir);

            // A glob() call alone isn't enough signal — plenty of WP code globs a directory for
            // reasons that have nothing to do with loading PHP (image galleries, asset lists).
            // Only trust it as a bulk-include when an actual include/require keyword also shows
            // up somewhere in the same file — still coarse (it doesn't prove the glob() result
            // is what gets required), but rules out the unrelated-glob case cheaply.
            if ($result->hasIncludeStatement && !empty($result->globIncludeDirs)) {
                foreach ($result->globIncludeDirs as $globDir) {
                    $exemptDir = trim($this->resolveGlobExemptDir($globDir, $callerRelDir), '/');
                    if ($exemptDir !== '') {
                        $this->dynamicLoadExemptDirs[] = $exemptDir;
                    }
                }
            }

            // scandir(SOME_CONFIGS_DIR) resolved via a define()-tracked constant — already a
            // project-root-relative path (see ParseResult's own doc comment on this field for
            // why), so trusted as-is rather than run through resolveGlobExemptDir's calling-file-
            // relative prefixing. Same hasIncludeStatement gate as the glob case above.
            if ($result->hasIncludeStatement && !empty($result->rootRelativeIncludeDirs)) {
                foreach ($result->rootRelativeIncludeDirs as $rootDirLiteral) {
                    $exemptDir = trim($rootDirLiteral, '/');
                    if ($exemptDir !== '') {
                        $this->dynamicLoadExemptDirs[] = $exemptDir;
                    }
                }
            }

            // A hand-rolled `spl_autoload_register(...)` autoloader (the non-Composer way any
            // class gets loaded in a namespaced OOP theme/plugin without its own composer.json —
            // real-world examples: Kadence, Hello Biz, Hello Elementor) has no per-file
            // include()/require() call the referenced-index can find either: the target path is
            // computed from the requested class name at runtime, inside the callback. Can't
            // resolve the callback's own target directory generically (one theme concatenates a
            // literal path fragment; another builds it from a constant plus a regex transform on
            // the class name) — but every real-world example registers the callback from the
            // same file that defines it, so its own directory tree is the closest honest scope
            // this analyzer can offer without executing the callback: it trusts the bootstrap
            // file's own location as "wherever this custom-loaded code lives", the same way
            // Composer's psr-4 mapping is trusted instead of proven. Unlike Composer's mapping
            // dirs though, this is only a candidate scope, not a blanket exemption — see
            // $autoloadRegisterExemptDirs's own docblock and isCandidate()'s use of it.
            foreach ($result->functionCalls as $call) {
                if ($call->name === 'spl_autoload_register') {
                    $this->autoloadRegisterExemptDirs[] = $callerRelDir;
                    break;
                }
            }

            // Foo::bulkLoad('inc') where bulkLoad()'s own body (possibly declared in a
            // different file entirely) turns out to contain a require/include — same
            // "co-occurrence, not proven causality" trade-off as the glob() case above, just
            // spanning two files instead of one. See PendingDirectoryLoaderCall's own docblock.
            foreach ($result->pendingDirectoryLoaderCalls as $call) {
                if (!isset($methodHasInclude[$call->receiverClass . '::' . $call->methodName])) {
                    continue;
                }
                $exemptDir = trim($this->resolveGlobExemptDir($call->literalArg, $callerRelDir), '/');
                if ($exemptDir !== '') {
                    $this->dynamicLoadExemptDirs[] = $exemptDir;
                }
            }
        }
    }

    /** $rootDir must already be rtrim'd — matches the convention isCandidate() relies on. */
    private function relativeDir(string $file, string $rootDir): string
    {
        $relative = ltrim(str_replace($rootDir, '', $file), '/');
        $dir = dirname($relative);
        return $dir === '.' ? '' : $dir;
    }

    /**
     * A glob() pattern's directory portion is relative to wherever the glob() call itself
     * lives — almost always spelled via `__DIR__`/`dirname(__FILE__)` concatenation, which is
     * exactly what "relative to the calling file" means. An empty/"." directory (glob()'ing
     * `*.php` with no subdirectory at all) means the glob targets the calling file's own
     * directory — i.e. sibling files, the "one bootstrap file globbing its own directory"
     * pattern.
     */
    private function resolveGlobExemptDir(string $globDirLiteral, string $callerRelDir): string
    {
        $globDirLiteral = trim($globDirLiteral, '/');
        if ($globDirLiteral === '' || $globDirLiteral === '.') {
            return $callerRelDir;
        }
        return $callerRelDir === '' ? $globDirLiteral : $callerRelDir . '/' . $globDirLiteral;
    }

    private function hasPageTemplateHeader(string $file): bool
    {
        $content = file_get_contents($file, length: 4096);
        return $content !== false && (bool) preg_match('/Template\s+Name\s*:/i', $content);
    }

    /**
     * "Title:" is the one header field WP core actually requires to register a block pattern
     * (developer.wordpress.org/themes/patterns/registering-patterns/) — Slug/Categories/etc. are
     * recommended but optional, so checking for those too would risk missing a real (if
     * minimally-tagged) pattern file and flagging it as dead code.
     */
    private function hasPatternFileHeader(string $file): bool
    {
        $content = file_get_contents($file, length: 4096);
        return $content !== false && (bool) preg_match('/^\s*\*\s*Title\s*:/mi', $content);
    }

    /**
     * @param list<\WpSpecter\Parser\ParseResult> $parseResults
     * @return array<string,bool>
     */
    private function buildReferencedIndex(array $parseResults, string $rootDir): array
    {
        $referenced = [];

        foreach ($parseResults as $result) {
            foreach ($result->templateRefs as $ref) {
                $normalized = $this->normalizePath($ref->path);
                if ($normalized === '') {
                    continue;
                }
                $referenced[$normalized] = true;
                $referenced[basename($normalized)] = true;
                $referenced[pathinfo($normalized, PATHINFO_FILENAME)] = true;
            }
            foreach ($result->phpPathStrings as $path) {
                $normalized = $this->normalizePath($path);
                if ($normalized === '') {
                    continue;
                }
                $referenced[$normalized] = true;
                $referenced[basename($normalized)] = true;
                $referenced[pathinfo($normalized, PATHINFO_FILENAME)] = true;
            }
        }

        // Literal wrapper arguments that form a fixed path and reach an include/template sink
        // only after one or more named/scoped calls — e.g. Blocksy's options/dynamic-styles
        // wrappers and their shared require helper. The resolver is intentionally bounded and
        // contributes exact paths to this same index rather than exempting a broad directory.
        foreach (LiteralPathPropagationResolver::resolve($parseResults) as $path) {
            $normalized = $this->normalizePath($path);
            if ($normalized === '') {
                continue;
            }
            $referenced[$normalized] = true;
            $referenced[basename($normalized)] = true;
            $referenced[pathinfo($normalized, PATHINFO_FILENAME)] = true;
        }

        // get_template_part( helper_fn() ) — helper_fn() is a project-defined function whose own
        // `return` statements (see PhpTokenParser's T_RETURN handling) resolve to one or more
        // literal paths. Merged across every scanned file first, since the helper and its caller
        // are routinely in different files — real-world example (OceanWP theme):
        // ocean_single_post_header_template() lives in inc/template-helpers.php, called from
        // inc/helpers.php.
        $functionLiteralReturns = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionLiteralReturns as $fnName => $literals) {
                if (!isset($functionLiteralReturns[$fnName])) {
                    $functionLiteralReturns[$fnName] = [];
                }
                array_push($functionLiteralReturns[$fnName], ...$literals);
            }
        }
        foreach ($parseResults as $result) {
            foreach ($result->pendingTemplateHelperCalls as $pending) {
                foreach ($functionLiteralReturns[$pending->helperFunction] ?? [] as $literal) {
                    $normalized = $this->normalizePath($this->prefixTemplateHelperPath($literal, $pending->templateFunction));
                    if ($normalized === '') {
                        continue;
                    }
                    $referenced[$normalized] = true;
                    $referenced[basename($normalized)] = true;
                    $referenced[pathinfo($normalized, PATHINFO_FILENAME)] = true;
                }
            }
        }

        // Foo::view($row_view) / Foo::view('settings/settings-' . $tab) — $row_view/$tab's
        // resolved candidate values (see PhpTokenParser's $pendingParamSuffixCalls) each paired
        // with Foo::view()'s own recorded `return <ignored> . $param . 'suffix';` template (see
        // $functionParamSuffixReturns), merged across every scanned file first since the callee
        // and its caller are routinely in different files — real-world example (wp-nested-pages):
        // Helpers::view() lives in app/Helpers.php, called from app/Entities/Listing/Listing.php
        // and app/Views/settings/settings.php.
        $functionParamSuffixReturns = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionParamSuffixReturns as $key => $suffixes) {
                if (!isset($functionParamSuffixReturns[$key])) {
                    $functionParamSuffixReturns[$key] = [];
                }
                array_push($functionParamSuffixReturns[$key], ...$suffixes);
            }
        }
        foreach ($parseResults as $result) {
            foreach ($result->pendingParamSuffixCalls as $pending) {
                $key = $pending->receiverClass . '::' . $pending->methodName;
                foreach ($functionParamSuffixReturns[$key] ?? [] as $suffix) {
                    foreach ($pending->argumentCandidates as $candidate) {
                        $normalized = $this->normalizePath($candidate . $suffix);
                        if ($normalized === '') {
                            continue;
                        }
                        $referenced[$normalized] = true;
                        $referenced[basename($normalized)] = true;
                        $referenced[pathinfo($normalized, PATHINFO_FILENAME)] = true;
                    }
                }
            }
        }

        foreach ($this->collectBlockJsonRefs($rootDir) as $ref) {
            $normalized = $this->normalizePath($ref);
            if ($normalized === '') {
                continue;
            }
            $referenced[$normalized] = true;
            $referenced[basename($normalized)] = true;
            $referenced[pathinfo($normalized, PATHINFO_FILENAME)] = true;
        }

        return $referenced;
    }

    /** @return list<string> */
    private function collectBlockJsonRefs(string $rootDir): array
    {
        if (!is_dir($rootDir)) {
            return [];
        }

        $refs = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->getFilename() !== 'block.json') {
                continue;
            }

            $raw = file_get_contents($file->getPathname());
            $json = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($json)) {
                continue;
            }

            foreach (self::BLOCK_JSON_RENDER_KEYS as $key) {
                if (isset($json[$key]) && is_string($json[$key])) {
                    $refs[] = $json[$key];
                }
            }
        }

        return $refs;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path, '/');
        return $path;
    }

    /**
     * Mirrors PhpTokenParser::parseTemplateRef()'s own get_header('x')/get_footer('x')/
     * get_sidebar('x') => header-x/footer-x/sidebar-x prefixing, for a literal resolved via
     * $functionLiteralReturns instead of a direct string argument — the same stem-prefix
     * convention applies regardless of which of the two ways the literal was discovered.
     */
    private function prefixTemplateHelperPath(string $literal, string $templateFunction): string
    {
        $prefix = match ($templateFunction) {
            'get_header' => 'header',
            'get_footer' => 'footer',
            'get_sidebar' => 'sidebar',
            default => null,
        };
        return $prefix !== null && $literal !== '' ? $prefix . '-' . $literal : $literal;
    }

    /** @param array<string,bool> $referenced */
    private function isReferencedByPartialMatch(string $normalizedPath, array $referenced): bool
    {
        foreach (array_keys($referenced) as $ref) {
            if ($ref !== '' && (
                str_ends_with($normalizedPath, '/' . $ref)
                || str_starts_with($normalizedPath, $ref . '/')
                // get_template_part( 'slug', $dynamic_name ) resolves to "slug-{$name}.php" —
                // the dynamic $name can't be known statically, so treat any "slug-*" file as
                // referenced once "slug" itself is a known get_template_part() call.
                || str_starts_with($normalizedPath, $ref . '-')
                || $normalizedPath === $ref
            )) {
                return true;
            }
        }
        return false;
    }
}

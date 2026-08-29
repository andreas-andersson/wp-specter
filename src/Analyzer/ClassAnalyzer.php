<?php

declare(strict_types=1);

namespace WpSpecter\Analyzer;

use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\Parser\ClassDef;
use WpSpecter\Parser\ParseResult;
use WpSpecter\Parser\PhpTokenParser;
use WpSpecter\Stubs\WpCoreContractMethods;

final class ClassAnalyzer
{
    // Methods PHP or WordPress core calls by contract (interface/base-class requirement), never
    // by a visible name reference anywhere in project code. Keyed by the interface/base class's
    // short name; only checked against a method's *own* declaring class's extends/implements
    // (see findClassHierarchy in PhpTokenParser) — not the whole inheritance chain, so a
    // grandchild class (extends My_Widget, which itself extends WP_Widget) won't get the
    // exemption. That's a known gap, not a silent one: it just falls back to normal matching.
    private const INTERFACE_CONTRACT_METHODS = [
        'ArrayAccess' => ['offsetExists', 'offsetGet', 'offsetSet', 'offsetUnset'],
        'Iterator' => ['current', 'key', 'next', 'rewind', 'valid'],
        'IteratorAggregate' => ['getIterator'],
        'Countable' => ['count'],
        'JsonSerializable' => ['jsonSerialize'],
        'Serializable' => ['serialize', 'unserialize'],
    ];

    // Every public method on WP core's Walker class (wp-includes/class-wp-walker.php):
    // start_lvl/end_lvl/start_el/end_el are the ones subclasses are documented to override, but
    // display_element, walk, paged_walk, get_number_of_root_elements and unset_children are also
    // public and legally overridable — and real-world themes do override display_element (e.g.
    // to patch WP core's current-menu-item class bug). All nine are only ever invoked by WP core
    // itself (wp_nav_menu(), wp_list_categories(), wp_list_comments(), ... calling ->walk() on
    // the walker instance), never by a visible name reference in project code.
    private const WALKER_CONTRACT_METHODS = [
        'start_lvl', 'end_lvl', 'start_el', 'end_el',
        'display_element', 'walk', 'paged_walk', 'get_number_of_root_elements', 'unset_children',
    ];

    // WP_Customize_Control's override points (wp-includes/class-wp-customize-control.php):
    // render_content()/render()/content_template() build the control's markup and JS
    // (Backbone/underscore) template, to_json() shapes the data handed to that JS template,
    // enqueue() loads control-specific scripts/styles, active_callback() gates visibility. All
    // called by the Customizer's own rendering pipeline (WP_Customize_Manager server-side,
    // customize-controls.js client-side for content_template), never by a visible name
    // reference in theme/plugin code.
    private const WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS = [
        'render_content', 'render', 'content_template', 'to_json', 'enqueue', 'active_callback',
    ];

    // WP_Customize_Section/WP_Customize_Panel's override points (wp-includes/class-wp-customize-
    // section.php, class-wp-customize-panel.php) — the Customizer's other two "sidebar tree"
    // node types alongside Control above, each with their own (overlapping but not identical)
    // set of override points called by the same Customizer rendering pipeline, never by a
    // visible name reference in theme/plugin code. Confirmed in the wild: GeneratePress's own
    // upsell section overrides render_template(), Hello Elementor's overrides render().
    private const WP_CUSTOMIZE_SECTION_CONTRACT_METHODS = ['render', 'render_template', 'json', 'active_callback'];
    // Panel has two more override points than Section (render_content(), content_template()) —
    // harmless to also list them on Section's own constant above if ever declared there by
    // mistake, but kept as separate named constants for clarity same as Control's own.
    private const WP_CUSTOMIZE_PANEL_CONTRACT_METHODS = [
        'render', 'render_content', 'render_template', 'content_template', 'json', 'active_callback',
    ];

    // WP_Upgrader_Skin's override points (wp-admin/includes/class-wp-upgrader-skin.php) — the
    // admin-UI hooks for a plugin/theme install-or-update screen (header/footer/feedback/error
    // messages, before/after actions, bulk-mode header/footer). All called internally by
    // WP_Upgrader and its subclasses (Plugin_Upgrader, Theme_Upgrader, ...) during an admin
    // install/update action, never by a visible name reference in project code. flush_output and
    // reset are Bulk_Upgrader_Skin's own additional override points, included here too since a
    // project class overriding either through any bulk-mode subclass should be exempt the same
    // way. Confirmed in the wild: both Blocksy's and OceanWP's own upgrader-skin classes
    // override feedback()/bulk_header()/bulk_footer()/decrement_update_count().
    private const WP_UPGRADER_SKIN_CONTRACT_METHODS = [
        'add_strings', 'set_result', 'set_upgrader', 'request_filesystem_credentials',
        'header', 'footer', 'error', 'feedback', 'before', 'after',
        'decrement_update_count', 'bulk_header', 'bulk_footer', 'hide_process_failed',
        'flush_output', 'reset',
    ];

    private const BASE_CLASS_CONTRACT_METHODS = [
        'WP_Widget' => ['widget', 'form', 'update'],
        'WP_REST_Controller' => ['register_routes'],
        'Walker' => self::WALKER_CONTRACT_METHODS,
        // WP core's own Walker subclasses. A project class rarely extends Walker directly — it
        // extends one of these (e.g. `class My_Nav_Walker extends Walker_Nav_Menu`), which sits
        // between it and Walker in the chain. Since these core classes are never present as a
        // ClassDef (they're WP core, not project code) and never reflectable either (WP core
        // isn't a Composer-autoloaded vendor package), the chain walk in isContractMethod()
        // would otherwise dead-end at the reflector and return false. Same method list as
        // 'Walker' above, since that's the full contract every one of these overrides from.
        'Walker_Nav_Menu' => self::WALKER_CONTRACT_METHODS,
        'Walker_Category' => self::WALKER_CONTRACT_METHODS,
        'Walker_CategoryDropdown' => self::WALKER_CONTRACT_METHODS,
        'Walker_Page' => self::WALKER_CONTRACT_METHODS,
        'Walker_PageDropdown' => self::WALKER_CONTRACT_METHODS,
        'Walker_Comment' => self::WALKER_CONTRACT_METHODS,
        // wp-admin-only core subclasses (nav-menus.php screen, category checklist metabox).
        // Same reasoning as the wp-includes subclasses above — never present as project ClassDef.
        'Walker_Nav_Menu_Edit' => self::WALKER_CONTRACT_METHODS,
        'Walker_Nav_Menu_Checklist' => self::WALKER_CONTRACT_METHODS,
        'Walker_Category_Checklist' => self::WALKER_CONTRACT_METHODS,
        // WP_Customize_Control's override points: render_content()/render()/content_template()
        // build the control's markup and JS template, to_json() shapes the data handed to that
        // JS template, enqueue() loads control-specific assets, active_callback() gates
        // visibility — every one of these is called by the Customizer's own rendering pipeline
        // (WP_Customize_Manager, and customize-controls.js on the JS side for content_template),
        // never by a visible name reference in theme code. Confirmed in the wild: Twenty
        // Twenty-One's own notice/color controls override render_content()/enqueue()/to_json().
        'WP_Customize_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        // WP core's own Control subclasses — same never-a-ClassDef, never-reflectable reasoning
        // as the Walker_* subclasses above; a project's own control usually extends one of these
        // rather than WP_Customize_Control directly.
        'WP_Customize_Background_Image_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Background_Position_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Code_Editor_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Color_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Cropped_Image_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Header_Image_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Image_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Media_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Nav_Menu_Auto_Add_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Nav_Menu_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Nav_Menu_Item_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Nav_Menu_Location_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Nav_Menu_Locations_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Nav_Menu_Name_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Site_Icon_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Theme_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Upload_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Sidebar_Block_Editor_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Widget_Area_Customize_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Widget_Form_Customize_Control' => self::WP_CUSTOMIZE_CONTROL_CONTRACT_METHODS,
        'WP_Customize_Section' => self::WP_CUSTOMIZE_SECTION_CONTRACT_METHODS,
        'WP_Customize_Panel' => self::WP_CUSTOMIZE_PANEL_CONTRACT_METHODS,
        // WP_Upgrader_Skin and its WP-core subclasses (wp-admin/includes/class-*-skin.php) —
        // never present as a project ClassDef or reflectable vendor class, same reasoning as the
        // Walker_* and WP_Customize_*_Control subclasses above.
        'WP_Upgrader_Skin' => self::WP_UPGRADER_SKIN_CONTRACT_METHODS,
        'Bulk_Upgrader_Skin' => self::WP_UPGRADER_SKIN_CONTRACT_METHODS,
        'Bulk_Plugin_Upgrader_Skin' => self::WP_UPGRADER_SKIN_CONTRACT_METHODS,
        'Bulk_Theme_Upgrader_Skin' => self::WP_UPGRADER_SKIN_CONTRACT_METHODS,
        'Plugin_Installer_Skin' => self::WP_UPGRADER_SKIN_CONTRACT_METHODS,
        'Plugin_Upgrader_Skin' => self::WP_UPGRADER_SKIN_CONTRACT_METHODS,
        'Theme_Installer_Skin' => self::WP_UPGRADER_SKIN_CONTRACT_METHODS,
        'Theme_Upgrader_Skin' => self::WP_UPGRADER_SKIN_CONTRACT_METHODS,
        'Automatic_Upgrader_Skin' => self::WP_UPGRADER_SKIN_CONTRACT_METHODS,
        'Language_Pack_Upgrader_Skin' => self::WP_UPGRADER_SKIN_CONTRACT_METHODS,
        'WP_Ajax_Upgrader_Skin' => self::WP_UPGRADER_SKIN_CONTRACT_METHODS,
    ];

    // Base classes whose subclasses get called entirely through framework naming-convention /
    // reflection, not through any fixed method-name contract — so no BASE_CLASS_CONTRACT_METHODS
    // list could ever be exhaustive. Every method (and the class itself) on a subclass is exempt.
    // Keyed by short name (same collision trade-off already accepted by the other curated lists
    // above, e.g. "Walker" isn't qualified either), value is the real FQCN. Unlike the other
    // lists, exempting a whole class is a big effect for a name collision to trigger by accident
    // (a project's own unrelated "Composer" class, say) — so isFullyExemptClass() checks the
    // FQCN via $useImports whenever the extending file actually imported one, and only falls
    // back to the bare short-name match when no import resolved it either way.
    private const FULLY_EXEMPT_BASE_CLASSES = [
        // Roots\Acorn\View\Composer (Sage 10+/Acorn theme scaffolding): subclass methods are
        // Blade-view data providers, discovered by matching an author-chosen method name against
        // the view's requested variable name at render time — never a literal call anywhere in
        // project code.
        'Composer' => 'Roots\Acorn\View\Composer',
    ];

    // Bounds the extends-chain walk in isContractMethod() so a cyclic or malformed extends
    // graph (which would never happen in valid PHP, but this is a token parser with no
    // semantic validation) can't spin forever.
    private const MAX_INHERITANCE_DEPTH = 50;

    public function __construct(private readonly PhpTokenParser $parser) {}

    /**
     * @param list<string> $files
     * @param list<string> $vendorAutoloadPaths
     * @param bool $suppressUnusedClassMethods When true (default), a method owned by a class
     *   that's itself already reported UnusedClass is dropped from the UnusedMethod findings —
     *   the class finding already says nothing in it is reachable, so the method finding adds
     *   no new information. Pass false to report both regardless (see --no-suppress-unused-
     *   class-methods).
     * @return list<Finding>
     */
    public function analyze(array $files, array $vendorAutoloadPaths = [], bool $suppressUnusedClassMethods = true): array
    {
        $parseResults = array_map(fn(string $f) => $this->parser->parse($f), $files);

        // Keyed by FQCN, not short name — two unrelated classes sharing a short name across
        // different namespaces (real-world case: Elementor's two distinct `Base_Route` classes)
        // now occupy two distinct keys instead of one silently overwriting the other's ClassDef.
        $classDefsByName = [];
        foreach ($parseResults as $result) {
            foreach ($result->classDefs as $def) {
                $classDefsByName[$def->fqcn] = $def;
            }
        }

        $reflector = new VendorClassReflector($vendorAutoloadPaths);

        // Filled in by findUnusedClasses() below with every FQCN it reports unused — a method
        // whose owner class is itself already reported as unused is redundant noise, the class
        // finding already says "nothing here is reachable," so every method under it says
        // nothing new.
        $unusedFqcns = [];
        $unusedClassFindings = $this->findUnusedClasses($parseResults, $classDefsByName, $unusedFqcns);
        $unusedClassNames = $suppressUnusedClassMethods ? $unusedFqcns : [];

        $findings = $unusedClassFindings;
        array_push($findings, ...$this->findUnusedMethods($parseResults, $classDefsByName, $reflector, $unusedClassNames));

        usort($findings, fn(Finding $a, Finding $b) => $a->file <=> $b->file ?: $a->line <=> $b->line);

        return $findings;
    }

    /**
     * @param list<ParseResult> $parseResults
     * @param array<string,ClassDef> $classDefsByName Keyed by FQCN.
     * @param array<string,true> $unusedFqcns Output: every FQCN reported unused, added to as
     *   found — lets analyze() suppress redundant UnusedMethod findings without a second pass.
     * @return list<Finding>
     */
    private function findUnusedClasses(array $parseResults, array $classDefsByName, array &$unusedFqcns = []): array
    {
        $referenced = [];
        foreach ($parseResults as $result) {
            foreach ($result->classReferences as $ref) {
                $referenced[$ref] = true;
            }
        }

        // WP core has several "hand a class name over as a plain string, WP instantiates it
        // internally" registration points — WP_Customize_Manager::register_panel_type()/
        // register_section_type()/register_control_type(), and filters like
        // 'block_parser_class'/'customize_dynamic_partial_class' whose callback returns a class
        // name string that WP then does `new $that_string(...)` on. None of these are `new`/
        // `instanceof`/`extends`/`implements`/`::` — the only shapes $classReferences tracks —
        // so the class looked permanently unused. Rather than curating every such WP API by name
        // (a losing battle — new ones keep appearing), any string literal already flowing into
        // the generic name-only $functionCalls pool (built for functions/methods; string
        // literals land there regardless of which call they're an argument to, or none at all —
        // see PhpTokenParser's blanket T_CONSTANT_ENCAPSED_STRING handling) is trusted as a
        // class reference too when it happens to match a real class name. Same imprecision
        // trade-off findUnusedMethods already accepts for its own $called fallback pool: two
        // unrelated things sharing a name will each look used if either is referenced this way.
        // Confirmed in the wild: Astra's own Astra_WP_Customize_Panel/_Section (register_*_type
        // shape) and Blocksy's own Blocksy_WP_Block_Parser/_Customize_Partial (filter-return
        // shape) were both false "unused class" findings before this fallback.
        $calledNames = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionCalls as $call) {
                $calledNames[$call->name] = true;
            }
        }

        $findings = [];
        foreach ($classDefsByName as $fqcn => $def) {
            if (!isset($referenced[$def->name]) && !isset($calledNames[$def->name])) {
                if ($this->isFullyExemptClass($fqcn, $classDefsByName)) {
                    continue;
                }
                $unusedFqcns[$fqcn] = true;
                $findings[] = new Finding(
                    type: FindingType::UnusedClass,
                    name: $def->name,
                    file: $def->file,
                    line: $def->line,
                    certainty: FindingCertainty::Error,
                    note: $def->kind === 'class' ? null : 'unused ' . $def->kind,
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<ParseResult> $parseResults
     * @param array<string,ClassDef> $classDefsByName Keyed by FQCN.
     * @param array<string,bool> $unusedClassNames Owner class FQCNs already reported as
     *   UnusedClass — their methods are skipped as redundant (see analyze()).
     * @return list<Finding>
     */
    private function findUnusedMethods(array $parseResults, array $classDefsByName, VendorClassReflector $reflector, array $unusedClassNames = []): array
    {
        // Calls PhpTokenParser could resolve to a concrete receiver class — $this->method(),
        // self::/parent::/static::method(), and Foo::method() with a literal class name — are
        // precise: they're never added to the generic $called pool below at all (see
        // findScopedCallTarget in the parser), so they can't cause an unrelated same-named
        // method on some other class to look used.
        $scopedCalled = [];
        foreach ($parseResults as $result) {
            foreach ($result->scopedMethodCalls as $call) {
                $scopedCalled[$call->receiverClass][$call->method] = true;
            }
        }

        // Class name => property name => the class assigned to it, from every
        // `$this->prop = new ClassName()` sighting (or constructor-promoted property) across
        // every file — merged before resolving $propertyMethodCalls below since a property set
        // in one file's method and read via $this->prop->method() from a different one (or a
        // different method in the same file, textually earlier or later — order doesn't matter
        // once everything is merged) both need the same complete picture.
        $propertyAssignedClasses = [];
        foreach ($parseResults as $result) {
            foreach ($result->propertyAssignedClasses as $className => $props) {
                foreach ($props as $propName => $assignedClass) {
                    $propertyAssignedClasses[$className][$propName] = $assignedClass;
                }
            }
        }

        // $this->prop->method() resolved against the merged property-type map above, feeding
        // directly into $scopedCalled itself — a resolved property-typed call is exactly as good
        // a signal as any other scoped call, and this lets every existing exemption/matching
        // mechanism below (contract methods, isUsedByDescendantReceiver, trait consumers, ...)
        // apply to it for free, with no separate matching path to keep in sync.
        foreach ($parseResults as $result) {
            foreach ($result->propertyMethodCalls as $call) {
                $trackedClass = $propertyAssignedClasses[$call->ownerClass][$call->property] ?? null;
                if ($trackedClass !== null) {
                    $scopedCalled[$trackedClass][$call->method] = true;
                }
            }
        }

        // Everything else — $obj->method() on a variable of unknown type, [$obj, 'method'] /
        // [Class::class, 'method'] array callbacks (the common add_action/add_filter shape),
        // string callbacks — still can't be attributed to a class, so it falls back to the same
        // name-only pool FunctionAnalyzer uses. This is where the remaining imprecision lives:
        // two unrelated classes sharing a method name (e.g. "render") will each look "used" if
        // either is called this way. Warning certainty (not Error) reflects that lower
        // confidence for whatever a finding's fallback-pool check alone couldn't rule out.
        $called = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionCalls as $call) {
                $called[$call->name] = true;
            }
        }

        // Method/function name => its own declared `: ReturnType`, resolved to a concrete class
        // — from every FunctionDef across every file (methods keyed by owner class, top-level
        // functions in a separate flat pool the same way $called already is). Consulted just
        // below to resolve $pendingReturnTypedCalls; built here (not in the parser) for the same
        // reason property types are — a factory method's own declaration and its caller are
        // routinely in different files.
        $methodReturnTypes = [];
        $functionReturnTypes = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionDefs as $def) {
                if ($def->returnType === null) {
                    continue;
                }
                if ($def->isMethod && $def->ownerClass !== null) {
                    $methodReturnTypes[$def->ownerClass][$def->name] = $def->returnType;
                } elseif (!$def->isMethod) {
                    $functionReturnTypes[$def->name] = $def->returnType;
                }
            }
        }

        // `$x = SomeFactory::make(); $x->method();` resolved against the return-type maps just
        // built. A resolved call feeds $scopedCalled directly, same as property-typed calls
        // above — every existing exemption/matching mechanism applies to it for free. When the
        // source call's own return type never resolves (an unknown function, no declared type,
        // a union/intersection type, ...), this falls back to the same unscoped $called pool the
        // call would have landed in before this feature existed at all — never a net precision
        // loss relative to the old behavior, only ever a gain when it does resolve.
        foreach ($parseResults as $result) {
            foreach ($result->pendingReturnTypedCalls as $call) {
                $returnType = $call->sourceReceiverClass !== null
                    ? ($methodReturnTypes[$call->sourceReceiverClass][$call->sourceMethod] ?? null)
                    : ($functionReturnTypes[$call->sourceMethod] ?? null);

                if ($returnType !== null) {
                    $scopedCalled[$returnType][$call->readMethod] = true;
                } else {
                    $called[$call->readMethod] = true;
                }
            }
        }

        // A callback built via string concatenation with a resolvable receiver — `array($this,
        // 'footer_html_' . $index)` inside a loop — can't be matched exactly, since the real
        // suffix is only known at runtime. $scopedCalledPrefixes mirrors $scopedCalled above but
        // is checked with str_starts_with() instead of an exact key lookup (matchesAnyPrefix()).
        // Deliberately scoped-only: an *unscoped* prefix pool would match against every method
        // project-wide by name prefix, and a short incidental one (confirmed against the Astra
        // theme — plain string-building like 'astra_' . $key, unrelated to any callback) would
        // hide genuinely dead code far more readily than an unscoped exact-name match does.
        $scopedCalledPrefixes = [];
        foreach ($parseResults as $result) {
            foreach ($result->scopedMethodCallPrefixes as $call) {
                $scopedCalledPrefixes[$call->receiverClass][] = $call->prefix;
            }
        }

        // Class names passed as a bare string to an API that dispatches across every public
        // method of that class by reflection (currently just WP_CLI::add_command() — see
        // PhpTokenParser). No fixed method name exists to check per class the way
        // BASE_CLASS_CONTRACT_METHODS does, so every method on the class is exempt, the same
        // whole-class effect as isFullyExemptClass() but triggered by a call site instead of an
        // extends/implements clause.
        $reflectionDispatchedClassNames = [];
        foreach ($parseResults as $result) {
            foreach ($result->reflectionDispatchedClassNames as $name) {
                $reflectionDispatchedClassNames[$name] = true;
            }
        }

        // trait name => list of classes/traits whose body directly `use`s it (see TraitUsage /
        // the T_USE handling in PhpTokenParser). A trait's own methods are never called on the
        // trait itself — only through whatever `use`s it — so isUsedByTraitConsumer() walks this
        // graph to widen the check below for methods owned by a trait.
        $traitUsers = [];
        foreach ($parseResults as $result) {
            foreach ($result->traitUsages as $usage) {
                $traitUsers[$usage->trait][] = $usage->user;
            }
        }

        // Base class name => every class whose own extends chain passes through it (multi-level
        // included: Astra_Get_Single_Page -> ... -> Astra_Abstract_Ability makes
        // 'Astra_Abstract_Ability' => [..., 'Astra_Get_Single_Page']). Real-world gap found in the
        // Astra theme: Astra_Abstract_Ability::register()/build_output_schema()/get_description()/
        // get_category() are shared, concrete (non-abstract) methods declared once on the base
        // class, then called from ~70 concrete subclasses as `Subclass::register()`,
        // `$this->build_output_schema()` (from inside the subclass), `$instance->get_description()`
        // — every one of those resolves the scoped call's receiver to the *subclass* name, never
        // the base class the method is actually declared on, so scopedCalled[ownerClass] alone
        // never matches. isUsedByDescendantReceiver() below widens the check the same direction
        // isUsedByPolymorphicCall() already does the opposite way (concrete class -> the
        // interface/ancestor a call was resolved to): here, base class -> any concrete descendant
        // a call was actually resolved to.
        $descendantsOf = [];
        foreach ($classDefsByName as $fqcn => $def) {
            $className = $fqcn;
            for ($depth = 0; $depth < self::MAX_INHERITANCE_DEPTH; $depth++) {
                $baseRef = ($classDefsByName[$className] ?? null)?->extends[0] ?? null;
                if ($baseRef === null) {
                    break;
                }
                $descendantsOf[$baseRef->fqcn][] = $fqcn;
                $className = $baseRef->fqcn;
            }
        }

        $findings = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionDefs as $def) {
                if (
                    !$def->isMethod
                    || $this->isMagicMethod($def->name)
                    || isset($unusedClassNames[$def->ownerClass ?? ''])
                    || isset($called[$def->name])
                    || isset($scopedCalled[$def->ownerClass ?? ''][$def->name])
                    || isset($reflectionDispatchedClassNames[$def->ownerClass ?? ''])
                    || $this->matchesAnyPrefix($def->name, $scopedCalledPrefixes[$def->ownerClass ?? ''] ?? [])
                    || $this->isFullyExemptClass($def->ownerClass, $classDefsByName)
                    || $this->isContractMethod($def->name, $def->ownerClass, $classDefsByName, $reflector)
                    || $this->isUsedByTraitConsumer($def->ownerClass, $def->name, $classDefsByName, $traitUsers, $scopedCalled)
                    || $this->isUsedByPolymorphicCall($def->ownerClass, $def->name, $classDefsByName, $scopedCalled)
                    || $this->isUsedByDescendantReceiver($def->ownerClass, $def->name, $descendantsOf, $scopedCalled)
                ) {
                    continue;
                }
                $findings[] = new Finding(
                    type: FindingType::UnusedMethod,
                    name: $def->name,
                    file: $def->file,
                    line: $def->line,
                    certainty: FindingCertainty::Warning,
                );
            }
        }

        return $findings;
    }

    /**
     * Walks the full extends chain from $ownerClass upward — not just its own declaration's
     * clause — since a class extending My_Base_Widget, which itself extends WP_Widget, still
     * inherits the widget()/form()/update() contract even though "WP_Widget" never appears on
     * $ownerClass's own ClassDef. implements is checked at every level walked too, so an
     * interface attached higher up the chain (rather than redeclared on every subclass) is
     * still honored. Bounded depth (MAX_INHERITANCE_DEPTH) guards against a cyclic/malformed
     * extends graph.
     *
     * Once the walk steps off the edge of what was scanned (an extends/implements target with
     * no ClassDef — a vendor dependency), $reflector takes over: PHP's own autoloader/Reflection
     * can see inside vendor code the token parser never touched. If that external class or
     * interface already declares $methodName, this is a real override of a vendor contract, not
     * dead code — a strict generalization of the curated lists above that needs no per-framework
     * entry, but only fires when a vendor autoloader was actually found (see
     * VendorClassReflector::isAvailable).
     *
     * $className always holds a real, already-resolved FQCN at every point in this walk (the
     * initial $ownerClass, or a chain link's own ClassRef::$fqcn) — handed to $reflector directly
     * once the walk steps off scanned project code, no separate use-import lookup needed.
     *
     * @param array<string,ClassDef> $classDefsByName Keyed by FQCN.
     */
    private function isContractMethod(string $methodName, ?string $ownerClass, array $classDefsByName, VendorClassReflector $reflector): bool
    {
        if ($ownerClass === null) {
            return false;
        }

        $className = $ownerClass;
        for ($depth = 0; $depth < self::MAX_INHERITANCE_DEPTH; $depth++) {
            $def = $classDefsByName[$className] ?? null;
            if ($def === null) {
                return $reflector->classHasMethod($className, $methodName);
            }

            foreach ($def->implements as $ifaceRef) {
                if (
                    in_array($methodName, self::interfaceContractMethods($ifaceRef->short), true)
                    || $reflector->classHasMethod($ifaceRef->fqcn, $methodName)
                ) {
                    return true;
                }
            }

            $baseRef = $def->extends[0] ?? null;
            if ($baseRef === null) {
                return false;
            }

            if (
                in_array($methodName, self::baseClassContractMethods($baseRef->short), true)
                || in_array($methodName, self::generatedContractMethods($baseRef->short), true)
            ) {
                return true;
            }

            $className = $baseRef->fqcn;
        }

        return false;
    }

    /**
     * Walks the extends chain the same way isContractMethod() does, checking each base short
     * name against FULLY_EXEMPT_BASE_CLASSES rather than a per-method list. $className is either
     * a class's own name (whole-class check from findUnusedClasses) or a method's owner class
     * (findUnusedMethods).
     *
     * Exempting an entire class is a much bigger effect than the per-method curated lists above,
     * so a bare short-name match isn't good enough on its own: a project with its own unrelated
     * "Composer" base class would otherwise get every subclass silently exempted. A chain link's
     * own ClassRef::$fqcn must resolve to the real FULLY_EXEMPT_BASE_CLASSES FQCN before this
     * counts as a match — `$ref->fqcn === $ref->short` (true only when the extending file has no
     * namespace, no matching `use` import, and no leading backslash on the reference) is the one
     * case with no real signal either way, and still falls back to the lenient short-name-only
     * match the same as before. This is strictly more precise than the pre-namespace-aware
     * version: a namespaced file's own unrelated `Composer` class (no `use` at all) previously
     * had no way to be told apart from a real `Roots\Acorn\View\Composer` subclass — merely being
     * namespaced now makes `$ref->fqcn !== $ref->short`, correctly ruling it out.
     *
     * @param array<string,ClassDef> $classDefsByName Keyed by FQCN.
     */
    private function isFullyExemptClass(?string $className, array $classDefsByName): bool
    {
        if ($className === null) {
            return false;
        }

        for ($depth = 0; $depth < self::MAX_INHERITANCE_DEPTH; $depth++) {
            $def = $classDefsByName[$className] ?? null;
            if ($def === null) {
                return false;
            }

            $baseRef = $def->extends[0] ?? null;
            if ($baseRef === null) {
                return false;
            }

            $expectedFqcn = self::exemptFqcnFor($baseRef->short);
            if ($expectedFqcn !== null && ($baseRef->fqcn === $expectedFqcn || $baseRef->fqcn === $baseRef->short)) {
                return true;
            }

            $className = $baseRef->fqcn;
        }

        return false;
    }

    /**
     * Case-insensitive lookups into the curated class-name-keyed lists above. PHP class-name
     * resolution is itself case-insensitive, so `extends Walker_Nav_menu` (a real typo found in
     * the wild — bootscore's own navwalker) still runs against WP core's actual `Walker_Nav_Menu`
     * at runtime; matching these lists with exact-case string keys would silently miss it and
     * produce a false "unused method" instead of applying the exemption.
     *
     * @return list<string>
     */
    private static function interfaceContractMethods(string $interface): array
    {
        static $ci = null;
        $ci ??= array_change_key_case(self::INTERFACE_CONTRACT_METHODS, CASE_LOWER);
        return $ci[strtolower($interface)] ?? [];
    }

    /** @return list<string> */
    private static function baseClassContractMethods(string $base): array
    {
        static $ci = null;
        $ci ??= array_change_key_case(self::BASE_CLASS_CONTRACT_METHODS, CASE_LOWER);
        return $ci[strtolower($base)] ?? [];
    }

    /**
     * Same case-insensitive lookup as baseClassContractMethods(), against
     * WpCoreContractMethods::methods() (see tools/generate-wp-contract-methods-stub.php)
     * instead of the hand-curated constant — a fallback net for any WP core base class the
     * hand-curated list doesn't name yet, never a replacement for it (isContractMethod() checks
     * both and takes either match).
     *
     * @return list<string>
     */
    private static function generatedContractMethods(string $base): array
    {
        static $ci = null;
        $ci ??= array_change_key_case(WpCoreContractMethods::methods(), CASE_LOWER);
        return $ci[strtolower($base)] ?? [];
    }

    private static function exemptFqcnFor(string $base): ?string
    {
        static $ci = null;
        $ci ??= array_change_key_case(self::FULLY_EXEMPT_BASE_CLASSES, CASE_LOWER);
        return $ci[strtolower($base)] ?? null;
    }

    /**
     * A trait's own method is never called on the trait directly (PHP doesn't allow that) — it's
     * used when a class (or another trait) that `use`s this trait, directly or transitively (one
     * trait `use`-ing another), calls it through a scoped receiver ($this->, self::, a tracked
     * variable) belonging to that consumer. Walks $traitUsers breadth-first from $ownerClass,
     * bounded and cycle-guarded the same way isContractMethod() walks the extends chain, checking
     * every consumer reached against $scopedCalled. No-ops (returns false immediately) unless
     * $ownerClass is itself a trait, since only a trait-owned method needs this indirection —
     * scopedCalled[$ownerClass][...] already covers a method owned by a real class.
     *
     * @param array<string,ClassDef> $classDefsByName
     * @param array<string,list<string>> $traitUsers
     * @param array<string,array<string,bool>> $scopedCalled
     */
    private function isUsedByTraitConsumer(?string $ownerClass, string $methodName, array $classDefsByName, array $traitUsers, array $scopedCalled): bool
    {
        if ($ownerClass === null || ($classDefsByName[$ownerClass]->kind ?? null) !== 'trait') {
            return false;
        }

        $queue = $traitUsers[$ownerClass] ?? [];
        $visited = [];

        for ($depth = 0; $queue !== [] && $depth < self::MAX_INHERITANCE_DEPTH; $depth++) {
            $next = [];
            foreach ($queue as $user) {
                if (isset($visited[$user])) {
                    continue;
                }
                $visited[$user] = true;

                if (isset($scopedCalled[$user][$methodName])) {
                    return true;
                }

                array_push($next, ...($traitUsers[$user] ?? []));
            }
            $queue = $next;
        }

        return false;
    }

    /**
     * A call PhpTokenParser resolves to a concrete receiver class only ever names the *static*
     * type at the call site — `function checkout(Shippable $method) { $method->calc(); }` records
     * ScopedMethodCall('Shippable', 'calc'), never 'FlatRate', even though FlatRate is what
     * actually runs at that call site if `$method` holds one. $scopedCalled[$ownerClass] alone
     * therefore never sees this call for a concrete implementer's own method — only the
     * interface/abstract-class declaration itself (whose own ownerClass now *is* 'Shippable',
     * see the parser's T_INTERFACE handling) does. This walks $ownerClass's own extends chain —
     * same structure as isContractMethod(), checking implements at every level too — looking for
     * a scoped call recorded against any interface or ancestor class along the way; finding one
     * means some code, somewhere, calls $methodName through a type $ownerClass satisfies, which
     * is the only signal static analysis can get for genuine runtime polymorphism.
     *
     * This can't tell dead code apart from a live implementation the same way scopedCalled[exact
     * class] does — if Shippable has two implementers and only one is ever actually reached at
     * runtime, both still look used. Same trade-off the untyped-receiver fallback pool already
     * makes project-wide; this is strictly narrower (only classes that actually implement/extend
     * the exact type the call was resolved to, not every same-named method anywhere).
     *
     * @param array<string,ClassDef> $classDefsByName Keyed by FQCN.
     * @param array<string,array<string,bool>> $scopedCalled
     */
    private function isUsedByPolymorphicCall(?string $ownerClass, string $methodName, array $classDefsByName, array $scopedCalled): bool
    {
        if ($ownerClass === null) {
            return false;
        }

        $className = $ownerClass;
        for ($depth = 0; $depth < self::MAX_INHERITANCE_DEPTH; $depth++) {
            $def = $classDefsByName[$className] ?? null;
            if ($def === null) {
                return false;
            }

            foreach ($def->implements as $ifaceRef) {
                if (isset($scopedCalled[$ifaceRef->fqcn][$methodName])) {
                    return true;
                }
            }

            $baseRef = $def->extends[0] ?? null;
            if ($baseRef === null) {
                return false;
            }

            if (isset($scopedCalled[$baseRef->fqcn][$methodName])) {
                return true;
            }

            $className = $baseRef->fqcn;
        }

        return false;
    }

    /**
     * A shared, concrete method declared on a base class ($ownerClass) but only ever called
     * through a concrete descendant's own receiver — `Subclass::method()`, `$this->method()`
     * from inside the subclass, `$instance->method()` where $instance is known to hold that
     * subclass — never matches scopedCalled[$ownerClass] directly, since every one of those
     * calls resolves its receiver to the descendant, not the class the method is actually
     * declared on. $descendantsOf (built once in findUnusedMethods) already carries every class
     * whose extends chain passes through $ownerClass, at any depth, so this is a flat lookup
     * rather than its own chain walk.
     *
     * @param array<string,list<string>> $descendantsOf
     * @param array<string,array<string,bool>> $scopedCalled
     */
    private function isUsedByDescendantReceiver(?string $ownerClass, string $methodName, array $descendantsOf, array $scopedCalled): bool
    {
        if ($ownerClass === null) {
            return false;
        }

        foreach ($descendantsOf[$ownerClass] ?? [] as $descendant) {
            if (isset($scopedCalled[$descendant][$methodName])) {
                return true;
            }
        }

        return false;
    }

    private function isMagicMethod(string $name): bool
    {
        return str_starts_with($name, '__');
    }

    /** @param list<string> $prefixes */
    private function matchesAnyPrefix(string $name, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }
}

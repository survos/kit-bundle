# survos/kit-bundle

Convention-based base class for Symfony 8 bundle development. Extend `SurvosBundle`, follow the directory conventions, and your commands, routes, Twig templates, and Stimulus assets wire up without boilerplate.

---

## Two audiences

This document distinguishes two roles:

- **Bundle author** — writes the bundle (extends `SurvosBundle`, ships it as a package)
- **App developer** — installs and configures the bundle in their Symfony application

---

## For bundle authors

### Extend `SurvosBundle`

```php
use Survos\Kit\SurvosBundle;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Survos\Kit\Traits\HasDoctrineEntities;

class SurvosClaimsBundle extends SurvosBundle
{
    use HasDoctrineEntities;
    use HasConfigurableRoutes;

    protected function entityNamespace(): string
    {
        return 'Survos\\ClaimsBundle\\Entity';
    }

    protected function doctrineAlias(): string
    {
        return 'Claims';
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();
        $this->addRouteOptions($children, '/claims');   // exposes routes_enabled + route_prefix
        $children
            ->arrayNode('list_predicates')->scalarPrototype()->end()->defaultValue([])->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder); // auto-scans Command/, Controller/

        $this->captureRouteConfig($config);
        $this->registerRouteLoader($builder);

        // Only register services that need non-default arguments
        $container->services()
            ->set(ClaimAggregator::class)
            ->arg('$listPredicates', $config['list_predicates']);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $this->addRouteLoaderCompilerPass($container);
    }
}
```

### What the base class handles automatically

| Convention | What is registered |
|---|---|
| `src/Command/` exists | All commands; auto-tagged `console.command` via autoconfigure |
| `src/Controller/` exists + `HasConfigurableRoutes` | All controllers wired as services; routes loaded via attribute scanning |
| `templates/` exists | Registered as a Twig namespace — `SurvosClaimsBundle` → `@SurvosClaims` |
| `assets/` exists + `ASSET_PACKAGE` const defined | Path registered with Symfony AssetMapper |
| `src/Entity/` exists + `HasDoctrineEntities` | ORM mapping registered automatically |

### Declaring the bundle dependency

Use `#[RequireBundle]` to declare that your bundle needs bundle-kit active. Symfony enforces
this automatically — no recipe, no documentation note, no forgotten `bundles.php` entry:

```php
#[RequireBundle(SurvosKitBundle::class)]
class SurvosClaimsBundle extends SurvosBundle { ... }
```

### Overriding conventions

Override protected properties when your layout differs from the standard:

```php
// Defaults — only override if non-standard
protected string $commandsDir    = 'src/Command';
protected string $controllersDir = 'src/Controller';
```

Override `twigNamespace()` to customise or disable Twig path registration:

```php
protected function twigNamespace(): ?string
{
    return 'MyCustomNamespace'; // null to skip entirely
}
```

Override `assetNamespace()` or define `ASSET_PACKAGE` to control AssetMapper registration:

```php
// Option A: constant (preferred)
public const ASSET_PACKAGE = 'claims';  // → @survos/claims

// Option B: method override
protected function assetNamespace(): ?string
{
    return '@survos/claims';
}
```

---

## For app developers

Most behaviour is automatic. The only knobs exposed are **route registration**, which apps
sometimes need to take over manually.

```yaml
# config/packages/survos_claims.yaml
survos_claims:
    routes_enabled: true       # default — set false to manage routes yourself
    route_prefix:  /claims     # optional URL prefix for all bundle routes
    list_predicates: []        # bundle-specific options vary per bundle
```

`routes_enabled: false` is the escape hatch: the bundle's controllers are still registered as
services, but no routes are loaded. Use this when you want to mount the bundle's routes under
a custom prefix in your own `config/routes/` file, or only expose a subset of them.

Commands, Twig paths, and AssetMapper registration have no on/off toggle — they are
unconditional. To suppress a command, use Symfony's built-in `console.command` tag exclusion
or remove the command class from the bundle if you're forking it.

---

## Before / after

A typical bundle before bundle-kit (~90 lines):

```php
// ❌ Before: every class listed, every path hard-coded
public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
{
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    $services->set(ClaimRepository::class);
    $services->set(ClaimIngestor::class);
    $services->set(ClaimProjector::class);
    $services->set(ClaimAggregator::class)->arg('$listPredicates', $config['list_predicates']);
    $services->set(ClaimsExportCommand::class);
    $services->set(ClaimsImportCommand::class);
    $services->set(ClaimsList::class);
    $services->set(ClaimsSummary::class);
    $services->set(ClaimConstantsExtension::class);
    $services->set(ClaimFunctionsExtension::class)->autoconfigure();
    // ...
}

public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
{
    $builder->prependExtensionConfig('doctrine', [
        'orm' => ['mappings' => [
            'SurvosClaimsBundle' => [
                'is_bundle' => false,
                'type'      => 'attribute',
                'dir'       => dirname(__DIR__) . '/src/Entity',   // repeated everywhere
                'prefix'    => 'Survos\\ClaimsBundle\\Entity',
                'alias'     => 'Claims',
            ],
        ]],
    ]);
    $builder->prependExtensionConfig('twig', [
        'paths' => [dirname(__DIR__) . '/templates' => 'SurvosClaims'],  // repeated everywhere
    ]);
}
```

After bundle-kit:

```php
// ✅ After: conventions replace boilerplate
class SurvosClaimsBundle extends SurvosBundle
{
    use HasDoctrineEntities;
    use HasConfigurableRoutes;

    // Doctrine + Twig handled by the base class
    protected function entityNamespace(): string { return 'Survos\\ClaimsBundle\\Entity'; }
    protected function doctrineAlias(): string { return 'Claims'; }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder); // scans Command/, Controller/

        // Only wire services that need arguments
        $container->services()
            ->set(ClaimAggregator::class)
            ->arg('$listPredicates', $config['list_predicates']);
    }
}
```

Commands, Twig paths, entity mappings, and route loading are gone — handled by convention.

---

## Requirements

- PHP 8.4+
- Symfony 8.1+
- `doctrine/orm` — optional, only needed when using `HasDoctrineEntities`
- `symfony/asset-mapper` — optional, only needed for Stimulus / UX bundles

# survos/kit-bundle

Convention-based base class for Symfony 8 bundle development.

The goal is simple: if a Survos bundle has conventional Symfony code, installing the bundle
should make that code available without a recipe full of repeated wiring.

- `src/Command/` classes with `#[AsCommand]` should become console commands.
- `src/Controller/` classes with `#[Route]` should be routable when the bundle opts into route loading.
- `templates/` should be available as a Twig namespace.
- `assets/` should be available to AssetMapper when the bundle declares an asset package.
- `src/Entity/` can be mapped into Doctrine when the bundle explicitly opts in.

Doctrine deserves special attention: when a bundle maps entities, installing that bundle changes
what Doctrine considers part of the application model. That is useful for bundles like
`key-value-bundle`, but it means the app developer must review and apply schema changes.

---

## Two audiences

This document distinguishes two roles:

- **Bundle author** — writes the bundle (extends `AbstractSurvosBundle` or `AbstractUxBundle`, ships it as a package)
- **App developer** — installs and configures the bundle in their Symfony application

---

## For bundle authors

### Extend `AbstractSurvosBundle`

```php
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\Traits\HasDoctrineEntities;

final class SurvosMyBundle extends AbstractSurvosBundle
{
    use HasDoctrineEntities;

    protected function doctrineAlias(): string
    {
        return 'My';
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder); // auto-scans Command/, Controller/

        // Only register services that need non-default arguments
        $container->services()
            ->set(MyService::class)
            ->arg('$config', $config);
    }
}
```

### What the base class handles automatically

| Convention | What is registered |
|---|---|
| `src/Command/` exists | Command classes are loaded as services; `#[AsCommand]` is auto-configured |
| `src/Controller/` exists | Controller classes are loaded as services |
| `src/Controller/` exists + `HasConfigurableRoutes` | Controller routes are loaded via attribute scanning |
| `templates/` exists | Registered as a Twig namespace — `SurvosMyBundle` → `@SurvosMy` |
| `assets/` exists + `ASSET_PACKAGE` const defined | Path registered with Symfony AssetMapper |
| `src/Entity/` exists + `HasDoctrineEntities` | ORM mapping is registered; see Doctrine section below |

### Declaring the bundle dependency

Use `#[RequiredBundle]` to declare that your bundle needs kit-bundle active. Symfony enforces
this automatically — no recipe, no documentation note, no forgotten `bundles.php` entry:

```php
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Survos\Kit\SurvosKitBundle;

#[RequiredBundle(SurvosKitBundle::class)]
final class SurvosMyBundle extends AbstractSurvosBundle { ... }
```

### Doctrine Entity Mapping

`HasDoctrineEntities` opts a bundle into Doctrine ORM mapping. It registers the bundle's
`src/Entity/` directory as attribute-mapped Doctrine entities during container prepending.

This is not a passive convenience. For app developers, installing a bundle that uses
`HasDoctrineEntities` means Doctrine may discover new mapped classes and therefore new tables,
columns, indexes, or relations.

Expected app workflow:

```bash
composer require survos/key-value-bundle
php bin/console doctrine:schema:update --dump-sql
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Bundle author opt-in:

```php
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\Traits\HasDoctrineEntities;

final class SurvosMyBundle extends AbstractSurvosBundle
{
    use HasDoctrineEntities;
}
```

By default, the entity namespace is derived from the bundle namespace:

```php
Survos\MyBundle\SurvosMyBundle
// maps src/Entity as:
Survos\MyBundle\Entity
```

Override `entityNamespace()` only when the entities are not in the standard namespace:

```php
protected function entityNamespace(): string
{
    return 'Survos\\MyBundle\\Model\\Doctrine';
}
```

Override `doctrineAlias()` only when the default alias, the bundle class name without `Bundle`,
is not what you want.

### Route Configuration

Every bundle that uses `HasConfigurableRoutes` should expose the same two app options:

```php
public function configure(DefinitionConfigurator $definition): void
{
    $children = $definition->rootNode()->children();
    $this->addRouteOptions($children, '/claims');

    // bundle-specific options...
    $children->end();
}
```

That gives apps parity with traditional `config/routes/*.yaml` imports:

- `routes_enabled: false` means "do not auto-import this bundle's controller attributes"
- `route_prefix: /custom-prefix` means "mount the bundle's routes under this URL prefix"

The bundle must then call `captureRouteConfig()` and `registerRouteLoader()` from
`loadExtension()`, and `addRouteLoaderCompilerPass()` from `build()`.

### UX / AssetMapper Bundles

Bundles that ship reusable frontend assets should extend `AbstractUxBundle`. It extends
`AbstractSurvosBundle`, enables AssetMapper registration by default, and registers itself as
a no-op compiler pass so asset-heavy bundles can override `process()` when they need compile
time wiring.

```php
use Survos\Kit\AbstractUxBundle;

final class SurvosIiifBundle extends AbstractUxBundle
{
    public const ASSET_PACKAGE = 'iiif';
}
```

### Overriding Conventions

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

A typical bundle before kit-bundle (~90 lines):

```php
// ❌ Before: every class listed, every path hard-coded
public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
{
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    $services->set(MyRepository::class);
    $services->set(MyImporter::class);
    $services->set(MyProjector::class);
    $services->set(MyService::class)->arg('$config', $config);
    $services->set(MyImportCommand::class);
    $services->set(MyExportCommand::class);
    $services->set(MyTwigExtension::class)->autoconfigure();
    // ...
}

public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
{
    $builder->prependExtensionConfig('doctrine', [
        'orm' => ['mappings' => [
            'SurvosMyBundle' => [
                'is_bundle' => false,
                'type'      => 'attribute',
                'dir'       => dirname(__DIR__) . '/src/Entity',   // repeated everywhere
                'prefix'    => 'Survos\\MyBundle\\Entity',
                'alias'     => 'My',
            ],
        ]],
    ]);
    $builder->prependExtensionConfig('twig', [
        'paths' => [dirname(__DIR__) . '/templates' => 'SurvosMy'],  // repeated everywhere
    ]);
}
```

After kit-bundle:

```php
// ✅ After: conventions replace boilerplate
final class SurvosMyBundle extends AbstractSurvosBundle
{
    use HasDoctrineEntities;
    use HasConfigurableRoutes;

    // Doctrine + Twig handled by the base class
    protected function doctrineAlias(): string { return 'My'; }

    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();
        $this->addRouteOptions($children, '/my');
        $children->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder); // scans Command/, Controller/
        $this->captureRouteConfig($config);
        $this->registerRouteLoader($builder);

        // Only wire services that need arguments
        $container->services()
            ->set(MyService::class)
            ->arg('$config', $config);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $this->addRouteLoaderCompilerPass($container);
    }
}
```

Commands, Twig paths, and entity mappings are handled by convention. Route loading keeps a
small explicit hook so each bundle can expose the standard `routes_enabled` and `route_prefix`
escape hatches.

---

## Requirements

- PHP 8.4+
- Symfony 8.1+
- `doctrine/orm` — optional, only needed when using `HasDoctrineEntities`
- `symfony/asset-mapper` — optional, only needed for Stimulus / UX bundles

<?php

declare(strict_types=1);

namespace Survos\Kit\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig helpers for referencing Survos UX Stimulus controllers by convention,
 * instead of hard-coding brittle "@survos/iiif-bundle/iiif-viewer" strings in templates.
 *
 * The canonical asset namespace for a Survos UX bundle is "@survos/{composer-package}"
 * (see AbstractSurvosBundle::deriveAssetNamespace() — only the "Survos" prefix is
 * stripped, NOT "Bundle": "@survos/iiif-bundle"). That equals the composer package
 * name, which is what Symfony UX resolves a controllers.json key against. Each
 * installed AbstractUxBundle contributes its
 * namespace + controller ids into the "survos_kit.ux_controllers" container
 * parameter (see AbstractUxBundle::contributeUxControllers()), and this extension
 * validates references against that registry so a typo or a missing bundle fails
 * loudly instead of rendering a controller nothing mounts.
 *
 * Required controller — throws if the bundle isn't installed or the id is wrong:
 *
 *   {{ stimulus_controller(survos_stimulus('iiif-bundle', 'iiif-viewer'), values) }}
 *
 * Optional integration — guard so it degrades cleanly instead of silently failing:
 *
 *   {% if survos_stimulus_exists('iiif-bundle', 'iiif-viewer') %}
 *     {{ stimulus_controller(survos_stimulus('iiif-bundle', 'iiif-viewer'), values) }}
 *   {% endif %}
 *
 * The package argument accepts short or full forms ('iiif', 'survos/iiif',
 * 'iiif-bundle', '@survos/iiif-bundle' …) — all resolve to '@survos/iiif-bundle'.
 */
final class SurvosStimulusExtension extends AbstractExtension
{
    /**
     * @param array<string, list<string>> $controllers namespace => controller ids,
     *                                                  e.g. ['@survos/iiif-bundle' => ['iiif-viewer', 'iiif-diva']]
     * @param bool                         $debug       only validate (and throw) in debug;
     *                                                  prod returns the reference unchecked
     */
    public function __construct(
        private readonly array $controllers = [],
        private readonly bool $debug = false,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('survos_stimulus', $this->stimulusController(...)),
            new TwigFunction('survos_stimulus_exists', $this->stimulusControllerExists(...)),
        ];
    }

    /**
     * Build the canonical Stimulus controller reference for a Survos UX bundle,
     * failing loudly if the bundle isn't installed or the controller doesn't exist.
     *
     * @param string $package    composer package, any form: 'iiif', 'survos/iiif',
     *                           'iiif-bundle', '@survos/iiif-bundle'
     * @param string $controller controller id as named in assets/package.json,
     *                           e.g. 'iiif-viewer', 'iiif-diva'
     */
    public function stimulusController(string $package, string $controller): string
    {
        $namespace = $this->namespace($package);

        // Validation is a dev-only check (like AbstractUxBundle's convention guard):
        // catch typos / missing bundles in dev & CI; never risk a prod page on render.
        if (!$this->debug) {
            return $namespace . '/' . $controller;
        }

        if (!isset($this->controllers[$namespace])) {
            throw new \RuntimeException(sprintf(
                'survos_stimulus(\'%s\', \'%s\'): no installed Survos UX bundle provides "%s". '
                . 'Is the bundle installed and does its composer.json carry the "symfony-ux" keyword? '
                . 'Installed UX namespaces: %s.',
                $package,
                $controller,
                $namespace,
                $this->controllers ? implode(', ', array_keys($this->controllers)) : '(none)',
            ));
        }

        if (!\in_array($controller, $this->controllers[$namespace], true)) {
            throw new \RuntimeException(sprintf(
                'survos_stimulus(\'%s\', \'%s\'): controller "%s" does not exist in "%s". Available: %s.',
                $package,
                $controller,
                $controller,
                $namespace,
                implode(', ', $this->controllers[$namespace]) ?: '(none)',
            ));
        }

        return $namespace . '/' . $controller;
    }

    /**
     * True when the given controller is provided by an installed UX bundle. Use to
     * guard optional integrations so a not-installed bundle degrades cleanly.
     */
    public function stimulusControllerExists(string $package, string $controller): bool
    {
        return \in_array($controller, $this->controllers[$this->namespace($package)] ?? [], true);
    }

    /**
     * Normalise a package reference to '@survos/{composer-package}', e.g.
     * '@survos/iiif-bundle'. Lenient about how much you spell out — all of
     * 'iiif', 'survos/iiif', '@survos/iiif', 'iiif-bundle', 'survos/iiif-bundle'
     * and '@survos/iiif-bundle' resolve to '@survos/iiif-bundle'. The "-bundle"
     * suffix is the composer package name Symfony UX resolves the controller
     * against, so it's appended when missing.
     */
    private function namespace(string $package): string
    {
        $slug = ltrim($package, '@');
        $slug = preg_replace('#^survos/#', '', $slug) ?? $slug;
        $slug = trim($slug, '/');
        if (!str_ends_with($slug, '-bundle')) {
            $slug .= '-bundle';
        }

        return '@survos/' . $slug;
    }
}

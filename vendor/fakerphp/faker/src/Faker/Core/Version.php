<?php

declare (strict_types=1);
namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Core;

use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension;
use FluentCart\FluentCart\FluentCart\FluentCart\Faker\Provider\DateTime;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Helper;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\NumberExtension;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\VersionExtension;
/**
 * @experimental This class is experimental and does not fall under our BC promise
 */
final class Version implements \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\VersionExtension
{
    private \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\NumberExtension $numberExtension;
    /**
     * @var string[]
     */
    private array $semverCommonPreReleaseIdentifiers = ['alpha', 'beta', 'rc'];
    public function __construct(\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\NumberExtension $numberExtension = null)
    {
        $this->numberExtension = $numberExtension ?: new \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Core\Number();
    }
    /**
     * Represents v2.0.0 of the semantic versioning: https://semver.org/spec/v2.0.0.html
     */
    public function semver(bool $preRelease = false, bool $build = false): string
    {
        return sprintf('%d.%d.%d%s%s', $this->numberExtension->numberBetween(0, 9), $this->numberExtension->numberBetween(0, 99), $this->numberExtension->numberBetween(0, 99), $preRelease && $this->numberExtension->numberBetween(0, 1) === 1 ? '-' . $this->semverPreReleaseIdentifier() : '', $build && $this->numberExtension->numberBetween(0, 1) === 1 ? '+' . $this->semverBuildIdentifier() : '');
    }
    /**
     * Common pre-release identifier
     */
    private function semverPreReleaseIdentifier(): string
    {
        $ident = \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Helper::randomElement($this->semverCommonPreReleaseIdentifiers);
        if ($this->numberExtension->numberBetween(0, 1) !== 1) {
            return $ident;
        }
        return $ident . '.' . $this->numberExtension->numberBetween(1, 99);
    }
    /**
     * Common random build identifier
     */
    private function semverBuildIdentifier(): string
    {
        if ($this->numberExtension->numberBetween(0, 1) === 1) {
            // short git revision syntax: https://git-scm.com/book/en/v2/Git-Tools-Revision-Selection
            return substr(sha1(\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Helper::lexify('??????')), 0, 7);
        }
        // date syntax
        return \FluentCart\FluentCart\FluentCart\FluentCart\Faker\Provider\DateTime::date('YmdHis');
    }
}
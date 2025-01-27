<?php declare(strict_types=1);

namespace Yireo\ExtensionChecker\Scan;

use Composer\Semver\Semver;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NotFoundException;
use Yireo\ExtensionChecker\Component\Component;
use Yireo\ExtensionChecker\Composer\ComposerFileProvider;
use Yireo\ExtensionChecker\Composer\ComposerProvider;
use Yireo\ExtensionChecker\Config\RuntimeConfig;
use Yireo\ExtensionChecker\Message\MessageBucket;
use Yireo\ExtensionChecker\Message\MessageGroupLabels;

class ScanComposerRequirements
{
    private ComposerFileProvider $composerFileProvider;
    private MessageBucket $messageBucket;
    private ComposerProvider $composerProvider;
    private RuntimeConfig $runtimeConfig;

    public function __construct(
        ComposerFileProvider $composerFileProvider,
        MessageBucket $messageBucket,
        ComposerProvider $composerProvider,
        RuntimeConfig $runtimeConfig
    ) {
        $this->composerFileProvider = $composerFileProvider;
        $this->messageBucket = $messageBucket;
        $this->composerProvider = $composerProvider;
        $this->runtimeConfig = $runtimeConfig;
    }

    /**
     * @param string $moduleName
     * @param Component[] $components
     * @return void
     * @throws FileSystemException
     * @throws NotFoundException
     */
    public function scan(string $moduleName, array $components)
    {
        $composerFile = $this->composerFileProvider->getComposerFileByModuleName($moduleName);
        $requirements = $composerFile->getRequirements();

        foreach ($components as $component) {
            $this->scanComponentWithComposerRequirements($component, $requirements, $moduleName);
        }

        foreach ($requirements as $requirement => $requirementVersion) {
            $this->scanComposerRequirementWithComponents($requirement, $requirementVersion, $components, $moduleName);
        }
    }

    /**
     * @param Component $component
     * @param array $requirements
     * @return void
     */
    private function scanComponentWithComposerRequirements(
        Component $component,
        array $requirements,
        string $moduleName
    ) {
        if ($component->isSoftRequirement()) {
            return;
        }

        if (array_key_exists($component->getPackageName(), $requirements)) {
            return;
        }

        if ($component->getPackageName() == 'symfony/console') {
            return;
        }

        $packageName = $component->getPackageName() ?? $component->getComponentName();
        $version = $component->getPackageVersion();
        $message = 'No composer dependency found for "' . $packageName . '"';
        $suggestion = sprintf('Current version is %s. ', $version);
        if ($this->composerProvider->shouldSuggestVersion($packageName)) {
            $suggestion .= sprintf('Perhaps use %s?', $this->composerProvider->getSuggestedVersion($version));
        }

        $this->messageBucket->add(
            $message,
            MessageGroupLabels::GROUP_MISSING_COMPOSER_DEP,
            $suggestion,
            $moduleName
        );
    }

    /**
     * @param string $requirement
     * @param string $requirementVersion
     * @param Component[] $components
     * @param string $moduleName
     * @return void
     */
    private function scanComposerRequirementWithComponents(
        string $requirement,
        string $requirementVersion,
        array $components,
        string $moduleName
    ) {
        $this->checkIfRequirementIsNeeded($requirement, $components, $moduleName);
        $this->checkIfComposerRequirementUsesWildCard($requirement, $requirementVersion, $moduleName);
        $this->checkPhpVersion($requirement, $requirementVersion, $moduleName);
    }

    /**
     * @param string $requirement
     * @param array $components
     * @param string $moduleName
     * @return void
     */
    private function checkIfRequirementIsNeeded(
        string $requirement,
        array $components,
        string $moduleName
    ) {
        if ($this->runtimeConfig->isHideNeedless()) {
            return;
        }

        if ($this->isComposerDependencyNeeded($requirement, $components)) {
            return;
        }

        if ($this->runtimeConfig->isComposerPackageWhitelisted($requirement)) {
            return;
        }

        $message = 'Composer requirement "' . $requirement . '" possibly not needed';
        $this->messageBucket->add(
            $message,
            MessageGroupLabels::GROUP_UNNECESSARY_COMPOSER_DEP,
            '',
            $moduleName
        );
    }

    /**
     * @param string $requirement
     * @param string $requirementVersion
     * @param string $moduleName
     * @return void
     */
    private function checkIfComposerRequirementUsesWildCard(
        string $requirement,
        string $requirementVersion,
        string $moduleName
    ) {
        if (preg_match('/^ext-/', $requirement)) {
            return;
        }

        if ($requirementVersion !== '*') {
            return;
        }

        $version = $this->composerProvider->getVersionByComposerName($requirement);
        $message = 'Composer requirement "' . $requirement . '" set to wildcard version';
        $suggestion = 'Current version is set to *. ';
        if ($this->composerProvider->shouldSuggestVersion($requirement)) {
            $suggestion .= sprintf('Perhaps use %s?', $this->composerProvider->getSuggestedVersion($version));
        }

        $this->messageBucket->add(
            $message,
            MessageGroupLabels::GROUP_WILDCARD_VERSION,
            $suggestion,
            $moduleName
        );
    }

    /**
     * @param string $requirement
     * @param string $requirementVersion
     * @param string $moduleName
     * @return void
     */
    private function checkPhpVersion(
        string $requirement,
        string $requirementVersion,
        string $moduleName
    ) {
        if ($requirement !== 'php') {
            return;
        }

        $currentVersion = phpversion();
        if (Semver::satisfies($currentVersion, $requirementVersion)) {
            return;
        }

        $message = 'Required PHP version "' . $requirementVersion . '" does not match your current PHP version ' . $currentVersion;
        $this->messageBucket->add(
            $message,
            MessageGroupLabels::GROUP_UNMET_REQUIREMENT,
            '',
            $moduleName
        );
    }

    /**
     * @param string $dependency
     * @param Component[] $components
     * @return bool
     */
    private function isComposerDependencyNeeded(string $dependency, array $components): bool
    {
        foreach ($components as $component) {
            if ($component->getPackageName() === $dependency) {
                return true;
            }
        }

        $validDependencies = [
            'php',
            'magento/magento-composer-installer',
            'phpstan/phpstan',
            'bitexpert/phpstan-magento',
            'yireo/magento2-integration-test-helper',
        ];

        if (\in_array($dependency, $validDependencies)) {
            return true;
        }

        if ($dependency === 'magento/framework') {
            return true;
        }

        if (str_starts_with($dependency, 'ext-')) {
            return true;
        }

        return false;
    }
}

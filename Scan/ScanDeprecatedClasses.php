<?php declare(strict_types=1);

namespace Yireo\ExtensionChecker\Scan;

use Yireo\ExtensionChecker\Exception\NoClassNameException;
use Yireo\ExtensionChecker\Message\MessageBucket;
use Yireo\ExtensionChecker\Message\MessageGroupLabels;
use Yireo\ExtensionChecker\PhpClass\ClassInspector;
use Yireo\ExtensionChecker\PhpClass\ModuleCollector;

class ScanDeprecatedClasses
{
    private MessageBucket $messageBucket;
    private ClassInspector $classInspector;
    private ModuleCollector $moduleCollector;

    public function __construct(
        MessageBucket $messageBucket,
        ClassInspector $classInspector,
        ModuleCollector $moduleCollector
    ) {
        $this->messageBucket = $messageBucket;
        $this->classInspector = $classInspector;
        $this->moduleCollector = $moduleCollector;
    }

    /**
     * @param string $moduleName
     * @return void
     */
    public function scan(string $moduleName)
    {
        $classNames = $this->moduleCollector->getClassNamesFromModule($moduleName);
        foreach ($classNames as $className) {
            try {
                $this->classInspector->setClassName($className);
            } catch(NoClassNameException $noClassNameException) {
                continue;
            }

            if ($this->classInspector->isDeprecated()) {
                $message = 'Usage of class "' . $className . '" is deprecated';
                $this->messageBucket->add(
                    $message,
                    MessageGroupLabels::GROUP_PHP_DEPRECATED,
                    '',
                    $moduleName
                );
            }
        }
    }
}

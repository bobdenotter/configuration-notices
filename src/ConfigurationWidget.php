<?php

declare(strict_types=1);

namespace BobdenOtter\ConfigurationNotices;

use Bolt\Extension\BaseExtension;
use Bolt\Widget\BaseWidget;
use Bolt\Widget\CacheAwareInterface;
use Bolt\Widget\CacheTrait;
use Bolt\Widget\Injector\AdditionalTarget;
use Bolt\Widget\Injector\RequestZone;
use Bolt\Widget\RequestAwareInterface;
use Bolt\Widget\StopwatchAwareInterface;
use Bolt\Widget\StopwatchTrait;
use Bolt\Widget\TwigAwareInterface;

class ConfigurationWidget extends BaseWidget implements TwigAwareInterface, RequestAwareInterface, CacheAwareInterface, StopwatchAwareInterface
{
    use CacheTrait;
    use StopwatchTrait;

    protected $name = 'Configuration Notices Widget';
    protected $target = AdditionalTarget::WIDGET_BACK_DASHBOARD_ASIDE_TOP;
    protected $priority = 100;
    protected $template = '@configuration-notices-widget/configuration.html.twig';
    protected $zone = RequestZone::BACKEND;
    protected $cacheDuration = -60;

    protected function run(array $params = []): ?string
    {
        /** @var BaseExtension $extension */
        $extension = $this->getExtension();

        $checks = new Checks($extension);
        $results = $checks->getResults();

        if (empty($results['notices'])) {
            return null;
        }

        $context = [
            'results' => $results,
        ];

        return parent::run($context);
    }
}

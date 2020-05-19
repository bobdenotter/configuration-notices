<?php

declare(strict_types=1);

namespace BobdenOtter\ConfigurationNotices;

use Bolt\Common\Exception\ParseException;
use Bolt\Common\Json;
use Bolt\Common\Str;
use Bolt\Extension\BaseExtension;
use Bolt\Version;
use Bolt\Widget\BaseWidget;
use Bolt\Widget\CacheAwareInterface;
use Bolt\Widget\CacheTrait;
use Bolt\Widget\Injector\AdditionalTarget;
use Bolt\Widget\Injector\RequestZone;
use Bolt\Widget\RequestAwareInterface;
use Bolt\Widget\StopwatchAwareInterface;
use Bolt\Widget\StopwatchTrait;
use Bolt\Widget\TwigAwareInterface;
use Symfony\Component\HttpClient\HttpClient;

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

        $checks = new Checks($extension->getBoltConfig(), $extension->getRequest());
        $results = $checks->getResults();

        $context = [
            'type' => 'error',
            'title' => 'Unable to fetch news!',
            'link' => '',
            'news' => "<p>Invalid JSON feed returned by <code> fdsfsdß</code></p><small>" . " cdscdscds </small>",
        ];

        return parent::run($context);
    }

    private function getResults()
    {
        dump(Checks::class);

        /** @var BaseExtension $extension */
        $extension = $this->extension;

        $checks = $extension->getContainer();

        dd($checks);
    }

}

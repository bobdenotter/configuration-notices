<?php

declare(strict_types=1);

namespace BobdenOtter\ConfigurationNotices;

use Bolt\Configuration\Config;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Tightenco\Collect\Support\Collection;

class Checks
{
    protected $defaultDomainPartials = ['.dev', 'dev.', 'devel.', 'development.', 'test.', '.test', 'new.', '.new', '.local', 'local.'];

    /** @var Config */
    private $boltConfig;

    /** @var Request */
    private $request;

    /** @var Collection */
    private $extensionConfig;

    private $notices = [];
    private $severity = 0;

    /** @var Container */
    private $container;

    public function __construct(Config $boltConfig, Request $request, Collection $extensionConfig, Container $container)
    {
        $this->boltConfig = $boltConfig;
        $this->request = $request;
        $this->extensionConfig = $extensionConfig;
        $this->container = $container;
    }

    public function getResults(): array
    {
        if ($this->request->get('_route') !== 'bolt_dashboard') {
            dump('no');
            return [];
        }

        $this->liveCheck();
        $this->newContentTypeCheck();

        return [
            'severity' => $this->severity,
            'notices' => $this->notices,
        ];
    }

    /**
     * Check whether the site is live or not.
     */
    private function liveCheck(): void
    {
        if ($this->getParameter('kernel.environment') === 'prod' && $this->getParameter('kernel.debug') !== '1') {
            return;
        }

        $host = parse_url($this->request->getSchemeAndHttpHost());

        // If we have an IP-address, we assume it's "dev"
        if (filter_var($host['host'], FILTER_VALIDATE_IP) !== false) {
            return;
        }

        $domainPartials = array_unique(array_merge(
            $this->extensionConfig->get('local_domains'),
            $this->defaultDomainPartials
        ));

        foreach ($domainPartials as $partial) {
            if (mb_strpos($host['host'], $partial) !== false) {
                return;
            }
        }

        $this->setSeverity(1);
        $this->setNotice(
            "It seems like this website is running on a <strong>non-development environment</strong>, 
             while development mode is enabled (<code>APP_ENV=dev</code> and/or <code>APP_DEBUG=1</code>). 
             Make sure debug is disabled in production environments. If you don't do this, it will 
             result in an extremely large <code>var/cache</code> folder and a measurable reduced 
             performance across all pages.",
            "If you wish to hide this message, add a key to your <abbr title='config/extensions/bobdenotter-configurationnotices.yaml'>
             config <code>yaml</code></abbr> file with a (partial) domain name in it, that should be 
             seen as a development environment: <code>local_domains: [ '.foo' ]</code>."
        );
    }

    private function newContentTypeCheck(): void
    {
        $fromParameters = explode('|', $this->getParameter('bolt.requirement.contenttypes'));

        foreach ($this->boltConfig->get('contenttypes') as $contentType) {
            if (! in_array($contentType->get('slug'), $fromParameters, true)) {
                $this->setSeverity(3);
                $this->setNotice(
                    sprintf("A new ContentType ('%s') was added. Make sure to clear the cache, so it shows up correctly.", $contentType->get('name'))
                );

                return;
            }
        }
    }

    private function setSeverity(int $severity): void
    {
        $this->severity = max($severity, $this->severity);
    }

    private function setNotice(string $notice, ?string $info = null): void
    {
        $this->notices[] = [
            'notice' => $notice,
            'info' => $info,
        ];
    }

    private function getParameter(string $parameter): ?string
    {
        return $this->container->getParameter($parameter);
    }
}

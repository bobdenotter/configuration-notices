<?php


namespace BobdenOtter\ConfigurationNotices;


use Bolt\Configuration\Config;
use Symfony\Component\HttpFoundation\Request;

class Checks
{
    protected $defaultDomainPartials = ['.dev', 'dev.', 'devel.', 'development.', 'test.', '.test', 'new.', '.new', '.local', 'local.'];

    /** @var Config */
    private $config;

    /** @var Request */
    private $request;

    private $results = [];

    public function __construct(Config $config, Request $request)
    {
        $this->config = $config;
        $this->request = $request;



    }

    public function getResults(): array
    {
        if ($this->request->get('_route') !== 'bolt_dashboard') {
            dump('no');
            return [];
        }

        $this->liveCheck();

        dump($this->results);

        return $this->results;
    }


    /**
     * Check whether the site is live or not.
     */
    protected function liveCheck()
    {
//        if (!$this->app['debug']) {
//            return;
//        }

        $host = parse_url($this->request->getSchemeAndHttpHost());

        // If we have an IP-address, we assume it's "dev"
        if (filter_var($host['host'], FILTER_VALIDATE_IP) !== false) {
            return;
        }

//        $domainPartials = (array) $this->app['config']->get('general/debug_local_domains', []);

        $domainPartials = array_unique(array_merge(
//            (array) $domainPartials,
            $this->defaultDomainPartials
        ));

        foreach ($domainPartials as $partial) {
            if (strpos($host['host'], $partial) !== false) {
                return;
            }
        }

        $this->results[] = [
            'severity' => 2,
            'notice'   => "It seems like this website is running on a <strong>non-development environment</strong>, while 'debug' is enabled. Make sure debug is disabled in production environments. If you don't do this, it will result in an extremely large <code>app/cache</code> folder and a measurable reduced performance across all pages.",
            'info'     => "If you wish to hide this message, add a key to your <code>config.yml</code> with a (partial) domain name in it, that should be seen as a development environment: <code>debug_local_domains: [ '.foo' ]</code>.",
        ];
    }
}
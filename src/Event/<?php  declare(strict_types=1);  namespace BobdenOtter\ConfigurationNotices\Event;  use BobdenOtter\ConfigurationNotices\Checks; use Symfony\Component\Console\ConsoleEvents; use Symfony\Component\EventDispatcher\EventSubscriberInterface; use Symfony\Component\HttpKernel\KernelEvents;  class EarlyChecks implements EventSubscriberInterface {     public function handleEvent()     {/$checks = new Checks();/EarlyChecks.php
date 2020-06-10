<?php

declare(strict_types=1);

namespace BobdenOtter\ConfigurationNotices\Event;

use BobdenOtter\ConfigurationNotices\Checks;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class EarlyChecks implements EventSubscriberInterface
{
    public function handleEvent()
    {
//        $checks = new Checks();
//
//        $results = $checks->getEarlyCheckResults();

        echo "Early";
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::CONTROLLER => ['handleEvent', 100],
            ConsoleEvents::COMMAND => ['handleEvent', 100],
        ];
    }
}

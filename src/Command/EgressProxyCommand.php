<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\PlatenConfig;
use App\Network\EgressProxy;
use App\Network\NetworkPolicy;
use App\Network\UpstreamProxy;
use Psr\Log\LoggerInterface;
use React\Dns\Config\Config as DnsConfig;
use React\Dns\Resolver\Factory as DnsFactory;
use React\EventLoop\Loop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'platen:egress-proxy', description: 'Runs the proxy that every renderer fetch goes through')]
final class EgressProxyCommand extends Command
{
    public function __construct(
        private readonly PlatenConfig $config,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'PLATEN_EGRESS_LISTEN')]
        private readonly string $listen,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('listen', null, InputOption::VALUE_REQUIRED, 'The address to listen on, e.g. 127.0.0.1:3128 (default: PLATEN_EGRESS_LISTEN)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $listen = $input->getOption('listen');
        $address = \is_string($listen) ? $listen : $this->listen;
        $loop = Loop::get();

        $dns = DnsConfig::loadSystemConfigBlocking();
        if ([] === $dns->nameservers) {
            $dns->nameservers[] = '8.8.8.8';
        }
        // An operator's outbound proxy (HTTP_PROXY, HTTPS_PROXY, NO_PROXY) comes after the policy.
        $environment = getenv();
        $proxy = new EgressProxy(
            NetworkPolicy::fromConfig($this->config),
            new DnsFactory()->createCached($dns, $loop),
            $loop,
            $this->logger,
            UpstreamProxy::fromEnvironment($environment, 'http'),
            UpstreamProxy::fromEnvironment($environment, 'https'),
        );
        $server = $proxy->listen($address);
        $output->writeln("Egress proxy listening on {$address}", OutputInterface::VERBOSITY_VERBOSE);

        $stop = static function () use ($server, $loop): void {
            $server->close();
            $loop->stop();
        };
        $loop->addSignal(\SIGTERM, $stop);
        $loop->addSignal(\SIGINT, $stop);
        $loop->run();

        return Command::SUCCESS;
    }
}

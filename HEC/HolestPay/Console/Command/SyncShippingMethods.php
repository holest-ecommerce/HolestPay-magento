<?php
namespace HEC\HolestPay\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use HEC\HolestPay\Model\ShippingMethodSyncService;
use Psr\Log\LoggerInterface;

class SyncShippingMethods extends Command
{
    /**
     * @var ShippingMethodSyncService
     */
    private $shippingSyncService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ShippingMethodSyncService $shippingSyncService
     * @param LoggerInterface $logger
     * @param string|null $name
     */
    public function __construct(
        ShippingMethodSyncService $shippingSyncService,
        LoggerInterface $logger,
        string $name = null
    ) {
        $this->shippingSyncService = $shippingSyncService;
        $this->logger = $logger;
        parent::__construct($name);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('holestpay:sync-shipping-methods')
            ->setDescription('Sync HolestPay shipping methods')
            ->addOption(
                'environment',
                'e',
                InputOption::VALUE_OPTIONAL,
                'Environment (sandbox/production)',
                'sandbox'
            )
            ->addOption(
                'merchant_site_uid',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Merchant Site UID'
            );

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $output->writeln('<info>Starting HolestPay shipping methods sync...</info>');

            $environment = $input->getOption('environment');
            $merchantSiteUid = $input->getOption('merchant_site_uid');

            if (!$merchantSiteUid) {
                $output->writeln('<error>Merchant Site UID is required. Use --merchant_site_uid option.</error>');
                return 1;
            }

            // Sample shipping methods data for testing
            $sampleShippingMethods = [
                [
                    'HPaySiteMethodId' => 124,
                    'Uid' => 'dexpressrs',
                    'Enabled' => true,
                    'Name' => 'DExpress Isporuka',
                    'Description' => 'Dostava kurirskom službom. Da bi videli paketomete u opciji isporuke u paket shop/paketomat navedite validan mobilni broj pre odabira.',
                    'Price Table' => [
                        [
                            'MaxWeight' => 500,
                            'Price' => 360
                        ],
                        [
                            'MaxWeight' => 1000,
                            'Price' => 440
                        ],
                        [
                            'MaxWeight' => 2000,
                            'Price' => 490
                        ],
                        [
                            'MaxWeight' => 5000,
                            'Price' => 650
                        ],
                        [
                            'MaxWeight' => 10000,
                            'Price' => 860
                        ],
                        [
                            'MaxWeight' => 20000,
                            'Price' => 1150
                        ],
                        [
                            'MaxWeight' => 30000,
                            'Price' => 1400
                        ],
                        [
                            'MaxWeight' => 50000,
                            'Price' => 1900
                        ]
                    ],
                    'After Max Weight Price Per Kg' => '40.00',
                    'Free Above Order Amount' => null,
                    'Additional cost' => '0.00',
                    'COD cost' => '0.00',
                    'ShippingCurrency' => 'RSD',
                    'Prices Include Vat' => null,
                    'SystemTitle' => 'D Express',
                    'Instant' => false,
                    'Order' => 0,
                    'UTS' => 1755946472000
                ],
                [
                    'HPaySiteMethodId' => 123,
                    'Uid' => 'cityexpressrs',
                    'Enabled' => true,
                    'Name' => 'CityExpress Isporuka',
                    'Description' => 'Dostava kurirskom službom. Da bi videli paketomete u opciji isporuke u paket shop/paketomat navedite validan mobilni broj pre odabira.',
                    'Price Table' => [
                        [
                            'MaxWeight' => 500,
                            'Price' => 462
                        ],
                        [
                            'MaxWeight' => 2000,
                            'Price' => 650
                        ],
                        [
                            'MaxWeight' => 5000,
                            'Price' => 924
                        ],
                        [
                            'MaxWeight' => 10000,
                            'Price' => 1146
                        ],
                        [
                            'MaxWeight' => 15000,
                            'Price' => 1548
                        ],
                        [
                            'MaxWeight' => 20000,
                            'Price' => 1626
                        ],
                        [
                            'MaxWeight' => 30000,
                            'Price' => 2010
                        ],
                        [
                            'MaxWeight' => 50000,
                            'Price' => 2856
                        ],
                        [
                            'MaxWeight' => 70000,
                            'Price' => 3840
                        ],
                        [
                            'MaxWeight' => 100000,
                            'Price' => 2016
                        ]
                    ],
                    'After Max Weight Price Per Kg' => '60.00',
                    'Free Above Order Amount' => null,
                    'Additional cost' => '0.00',
                    'COD cost' => '0.00',
                    'ShippingCurrency' => 'RSD',
                    'Prices Include Vat' => true,
                    'SystemTitle' => 'City Express',
                    'Instant' => false,
                    'Order' => 0,
                    'UTS' => 1755944337000
                ]
            ];

            $output->writeln(sprintf('<info>Syncing %d sample shipping methods...</info>', count($sampleShippingMethods)));

            $result = $this->shippingSyncService->syncShippingMethods($sampleShippingMethods);

            if ($result) {
                $output->writeln('<info>Shipping methods synced successfully!</info>');
                $output->writeln('<info>You can now see HolestPay shipping methods on the checkout page.</info>');
            } else {
                $output->writeln('<error>Failed to sync shipping methods.</error>');
                return 1;
            }

            return 0;

        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            $this->logger->error('HolestPay: Error in sync shipping methods command', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}

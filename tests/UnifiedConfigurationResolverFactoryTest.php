<?php

declare(strict_types=1);

namespace Keboola\ConfigurationVariablesResolver\Tests;

use Keboola\ConfigurationVariablesResolver\UnifiedConfigurationResolverFactory;
use Keboola\ServiceClient\ServiceClient;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\StorageApiToken;
use Keboola\VaultApiClient\ApiClientConfiguration as VaultVariablesApiClientConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class UnifiedConfigurationResolverFactoryTest extends TestCase
{
    private readonly Mockserver $variablesApiMock;

    protected function setUp(): void
    {
        $this->variablesApiMock = new Mockserver;
        $this->variablesApiMock->reset();
    }

    public function testResolveConfigurationWithSharedCodeAndVault(): void
    {
        $this->variablesApiMock->expect([
            'httpRequest' => [
                'method' => 'GET',
                'path' => '/variables/scoped/branch/branch-id',
                'headers' => [
                    'X-StorageApi-Token' => 'token',
                ],
            ],
            'httpResponse' => [
                'statusCode' => 200,
                'body' => json_encode([
                    [
                        'key' => 'vaultVariable',
                        'value' => 'vaultVariableValue',
                        'hash' => 'abcdef',
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $branchAwareClientMock = $this->createMock(BranchAwareClient::class);
        $branchAwareClientMock->method('apiGet')
            ->willReturnCallback(static fn($url) => match ($url) {
                'components/keboola.variables/configs/variablesId' => ['configuration' => [
                    'variables' => [
                        [
                            'name' => 'variable',
                            'type' => 'string',
                        ],
                    ],
                ]],
                'components/keboola.variables/configs/variablesId/rows/variablesRowId' => ['configuration' => [
                    'values' => [
                        [
                            'name' => 'variable',
                            'value' => 'variableValue',
                        ],
                    ],
                ]],
                'components/keboola.shared-code/configs/sharedCodeId/rows/sharedCodeRowId' => ['configuration' => [
                    'code_content' => [
                        '{{ variable }}',
                    ],
                ]],
                default => null,
            })
        ;

        $clientWrapperMock = $this->createMock(ClientWrapper::class);
        $clientWrapperMock->method('getBranchId')
            ->willReturn('branch-id');
        $clientWrapperMock->method('getBranchClient')
            ->willReturn($branchAwareClientMock);
        $clientWrapperMock->method('getToken')
            ->willReturn(new StorageApiToken([], 'token'));

        $unifiedVariablesResolver = $this->getFactory()->createResolver($clientWrapperMock);

        $results = $unifiedVariablesResolver->resolveConfiguration(
            [
                'variables_id' => 'variablesId',
                'variables_values_id' => 'variablesRowId',
                'parameters' => [
                    'parameter_with_variables' => '{{ variable }} {{ vault.vaultVariable }}',
                    'parameter_with_shared_code' => ['{{ sharedCodeRowId }}'], // resolved in arrays only
                ],
                'shared_code_id' => 'sharedCodeId',
                'shared_code_row_ids' => [
                    'sharedCodeRowId',
                ],
            ],
        );

        self::assertEquals(
            [
                'variables_id' => 'variablesId',
                'variables_values_id' => 'variablesRowId',
                'parameters' => [
                    'parameter_with_variables' => 'variableValue vaultVariableValue',
                    'parameter_with_shared_code' => ['variableValue'], // shared code must be resolved before variables
                ],
                'shared_code_id' => 'sharedCodeId',
                'shared_code_row_ids' => [
                    'sharedCodeRowId',
                ],
            ],
            $results->configuration, // we are working with only one configuration
        );
    }

    public function testResolveConfigurationVariableIdOverride(): void
    {
        $branchAwareClientMock = $this->createMock(BranchAwareClient::class);
        $branchAwareClientMock->method('apiGet')
            ->willReturnCallback(static fn($url) => match ($url) {
                'components/keboola.variables/configs/variablesId' => ['configuration' => [
                    'variables' => [
                        [
                            'name' => 'variable',
                            'type' => 'string',
                        ],
                    ],
                ]],
                'components/keboola.variables/configs/variablesId/rows/variablesRowIdOne' => ['configuration' => [
                    'values' => [
                        [
                            'name' => 'variable',
                            'value' => 'variableValueOne',
                        ],
                    ],
                ]],
                'components/keboola.variables/configs/variablesId/rows/variablesRowIdTwo' => ['configuration' => [
                    'values' => [
                        [
                            'name' => 'variable',
                            'value' => 'variableValueTwo',
                        ],
                    ],
                ]],
                default => null,
            })
        ;

        $clientWrapperMock = $this->createMock(ClientWrapper::class);
        $clientWrapperMock->method('getBranchId')
            ->willReturn('branch-id');
        $clientWrapperMock->method('getBranchClient')
            ->willReturn($branchAwareClientMock);
        $clientWrapperMock->method('getToken')
            ->willReturn(new StorageApiToken([], 'token'));

        $unifiedVariablesResolver = $this->getFactory()->createResolver(
            $clientWrapperMock,
            variableValuesId: 'variablesRowIdTwo',
        );

        $results = $unifiedVariablesResolver->resolveConfiguration(
            [
                'variables_id' => 'variablesId',
                'variables_values_id' => 'variablesRowIdOne',
                'parameters' => [
                    'parameter_with_variables' => '{{ variable }}',
                ],
            ],
        );

        self::assertEquals(
            [
                'variables_id' => 'variablesId',
                'variables_values_id' => 'variablesRowIdOne',
                'parameters' => [
                    'parameter_with_variables' => 'variableValueTwo',
                ],
            ],
            $results->configuration, // we are working with only one configuration
        );
    }

    public function testResolveConfigurationVariableDataOverride(): void
    {
        $branchAwareClientMock = $this->createMock(BranchAwareClient::class);
        $branchAwareClientMock->method('apiGet')
            ->willReturn(['configuration' => []]);

        $clientWrapperMock = $this->createMock(ClientWrapper::class);
        $clientWrapperMock->method('getBranchId')
            ->willReturn('branch-id');
        $clientWrapperMock->method('getBranchClient')
            ->willReturn($branchAwareClientMock);
        $clientWrapperMock->method('getToken')
            ->willReturn(new StorageApiToken([], 'token'));

        $unifiedVariablesResolver = $this->getFactory()->createResolver(
            $clientWrapperMock,
            variableValuesData: [
                'values' => [
                    [
                        'name' => 'inlinedVariable',
                        'value' => 'variableValue',
                    ],
                ],
            ],
        );

        $results = $unifiedVariablesResolver->resolveConfiguration(
            [
                'variables_id' => 'variablesId', // must be present even for inlined variables
                'parameters' => [
                    'parameter_with_variables' => '{{ inlinedVariable }}',
                ],
            ],
        );

        self::assertEquals(
            [
                'variables_id' => 'variablesId',
                'parameters' => [
                    'parameter_with_variables' => 'variableValue',

                ],
            ],
            $results->configuration, // we are working with only one configuration
        );
    }

    private function getFactory(): UnifiedConfigurationResolverFactory
    {
        $serviceClient = $this->createMock(ServiceClient::class);
        $serviceClient->method('getVaultUrl')->willReturn($this->variablesApiMock->getServerUrl());

        $vaultVariablesApiClientConfiguration = new VaultVariablesApiClientConfiguration();

        return new UnifiedConfigurationResolverFactory(
            $serviceClient,
            $vaultVariablesApiClientConfiguration,
            new NullLogger,
        );
    }
}

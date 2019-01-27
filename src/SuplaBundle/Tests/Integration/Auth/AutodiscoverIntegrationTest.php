<?php
/*
 Copyright (C) AC SOFTWARE SP. Z O.O.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.
 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.
 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace SuplaBundle\Tests\Integration\Auth;

use SuplaBundle\Auth\AutodiscoverPublicClientStub;
use SuplaBundle\Entity\OAuth\ApiClient;
use SuplaBundle\Entity\User;
use SuplaBundle\Model\TargetSuplaCloud;
use SuplaBundle\Supla\SuplaAutodiscover;
use SuplaBundle\Tests\Integration\IntegrationTestCase;
use SuplaBundle\Tests\Integration\Traits\ResponseAssertions;

/**
 * For these tests to run, you need to launch your local instance of SUPLA Autodiscover from https://github.com/SUPLA/supla-autodiscover
 * Then, update app/config/config_local.yml so the "Real" Autodiscover is being used in the tests instead of the Mocked one:
 */
/*
supla:
  autodiscover_url: http://suplaad.local
parameters:
  act_as_broker_cloud: true
  supla_protocol: http
services:
  SuplaBundle\Supla\SuplaAutodiscover: '@SuplaBundle\Supla\SuplaAutodiscoverReal'
*/

/**
 * These tests are disabled by default because of the demand for the specific environment. To run them, change the group name below (this
 * gruop name is excluded in app/phpunit.xml configuration file).
 * @group AutodiscoverIntegrationTest
 */
class AutodiscoverIntegrationTest extends IntegrationTestCase {
    use ResponseAssertions;

    const AD_PROJECT_PATH = \AppKernel::VAR_PATH . '/../../supla-autodiscover';

    /** @var SuplaAutodiscover */
    private $autodiscover;
    private $clientId;
    private $clientSecret;

    /** @before */
    public function clearState() {
        @unlink(SuplaAutodiscover::TARGET_CLOUD_TOKEN_SAVE_PATH);
        @unlink(SuplaAutodiscover::PUBLIC_CLIENTS_SAVE_PATH);
        $path = realpath(self::AD_PROJECT_PATH);
        exec("$path/vendor/bin/phpunit -c $path/tests --filter testInvalidUrl", $output); // clears the database
        $this->autodiscover = $this->container->get(SuplaAutodiscover::class);
        $this->executeAdCommand('public-clients:create');
        $publicClientConfigPath = $path . '/var/public-clients/0001.yml';
        $config = file_get_contents($publicClientConfigPath);
        $config = str_replace('enabled: false', 'enabled: true', $config);
        preg_match('#clientId: (.+)#', $config, $matches);
        $this->clientId = trim($matches[1]);
        preg_match('#secret: (.+)#', $config, $matches);
        $this->clientSecret = trim($matches[1]);
        file_put_contents($publicClientConfigPath, $config);
        $this->executeAdCommand('public-clients:update 1');
    }

    public function testRegisteringTargetCloud() {
        $result = $this->executeAdCommand('target-clouds:registration-tokens:issue http://supla.local local@supla.org');
        $this->assertCount(3, $result);
        $command = $result[2];
        $result = $this->executeCommand(substr($command, strlen('php bin/console ')));
        $this->assertContains('correctly', $result);
    }

    public function testRegisteringUserInAd() {
        $this->registerUser();
        $user = $this->getEntityManager()->find(User::class, 1);
        $this->getEntityManager()->remove($user); // let's pretend the user does not exist here
        $this->getEntityManager()->flush();
        $server = $this->autodiscover->getAuthServerForUser('adtest@supla.org');
        $this->assertFalse($server->isLocal());
    }

    public function testDeletingUserDeletesItInAd() {
        $this->registerUser();
        $result = $this->executeCommand('supla:delete-user adtest@supla.org');
        $this->assertContains('has been deleted', $result);
        $server = $this->autodiscover->getAuthServerForUser('adtest@supla.org');
        $this->assertTrue($server->isLocal());
    }

    public function testGetTargetCloudClientId() {
        $this->testRegisteringTargetCloud();
        $this->treatAsBroker();
        $targetCloud = new TargetSuplaCloud('http://supla.local', true);
        $localClientId = $this->autodiscover->getTargetCloudClientId($targetCloud, $this->clientId);
        $this->assertNotNull($localClientId);
        return $localClientId;
    }

    public function testGetPublicIdBasedOnMappedId() {
        $localClientId = $this->testGetTargetCloudClientId();
        $publicId = $this->autodiscover->getPublicIdBasedOnMappedId($localClientId);
        $this->assertEquals($publicId, $this->clientId);
    }

    public function testUpdateTargetCloudCredentials() {
        $localClientId = $this->testGetTargetCloudClientId();
        $clientMock = $this->createMock(ApiClient::class);
        $clientMock->method('getPublicId')->willReturn('1_local');
        $clientMock->method('getSecret')->willReturn('XXX');
        $this->autodiscover->updateTargetCloudCredentials($localClientId, $clientMock);
        $publicId = $this->autodiscover->getPublicIdBasedOnMappedId('1_local');
        $this->assertEquals($publicId, $this->clientId);
    }

    public function testFetchTargetCloudClientSecret() {
        $this->testUpdateTargetCloudCredentials();
        $client = new AutodiscoverPublicClientStub($this->clientId);
        $client->setSecret($this->clientSecret);
        $data = $this->autodiscover->fetchTargetCloudClientSecret($client, new TargetSuplaCloud('http://supla.local', true));
        $this->assertEquals('XXX', $data['secret']);
        $this->assertEquals('1_local', $data['mappedClientId']);
    }

    public function testIssueRegistrationTokenForTargetCloud() {
        $this->testRegisteringTargetCloud();
        $this->treatAsBroker();
        $targetCloud = new TargetSuplaCloud('http://supla2.local');
        $token = $this->autodiscover->issueRegistrationTokenForTargetCloud($targetCloud, 'some@email.com');
        $this->assertNotNull($token);
    }

    public function testGetPublicClient() {
        $this->testRegisteringTargetCloud();
        $clientData = $this->autodiscover->getPublicClient($this->clientId);
        $this->assertNotNull($clientData);
        $this->assertEquals('New Public Client', $clientData['name']);
    }

    private function registerUser() {
        $this->testRegisteringTargetCloud();
        $this->treatAsBroker();
        $userData = [
            'email' => 'adtest@supla.org',
            'regulationsAgreed' => true,
            'password' => 'supla123',
            'timezone' => 'Europe/Warsaw',
        ];
        $client = $this->createClient();
        $client->apiRequest('POST', '/api/register', $userData);
        $this->assertStatusCode(201, $client->getResponse());
    }

    private function treatAsBroker(string $targetCloudUrl = 'http://supla.local') {
        $this->executeAdCommand("target-clouds:update $targetCloudUrl --set-broker --no-interaction");
    }

    private function executeAdCommand(string $command): array {
        $path = realpath(self::AD_PROJECT_PATH) . '/autodiscover';
        exec("php $path $command", $output);
        return $output;
    }
}

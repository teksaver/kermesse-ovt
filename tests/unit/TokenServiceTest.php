<?php

use CodeIgniter\Test\CIUnitTestCase;
use App\Services\TokenService;
use App\Services\IssuedToken;
use App\Services\TokenValidationResult;
use App\Models\AccessTokenModel;

/**
 * Unit tests for TokenService.
 *
 * Uses a mock AccessTokenModel to avoid DB dependency.
 *
 * @internal
 */
final class TokenServiceTest extends CIUnitTestCase
{
    public function testIssueOwnerValidationTokenReturnsIssuedToken(): void
    {
        $mockModel = $this->createMock(AccessTokenModel::class);
        $mockModel->method('skipValidation')->willReturnSelf();
        $mockModel->method('insert')->willReturn(42);

        $config = config('Kermesse');
        $service = new TokenService($mockModel, $config);

        $result = $service->issueOwnerValidationToken(1, 1, 'test@example.com');

        $this->assertInstanceOf(IssuedToken::class, $result);
        $this->assertSame(42, $result->tokenId);
        $this->assertNotEmpty($result->rawToken);
    }

    public function testTokenHashIsSha256Of64Characters(): void
    {
        $capturedData = null;

        $mockModel = $this->createMock(AccessTokenModel::class);
        $mockModel->method('skipValidation')->willReturnSelf();
        $mockModel->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $service = new TokenService($mockModel, config('Kermesse'));
        $result  = $service->issueOwnerValidationToken(1, 1, 'test@example.com');

        // The stored hash must be 64 hex chars (SHA-256)
        $this->assertNotNull($capturedData);
        $this->assertSame(64, strlen($capturedData['token_hash']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $capturedData['token_hash']);

        // The stored hash must match SHA-256 of the raw token
        $expectedHash = hash('sha256', $result->rawToken);
        $this->assertSame($expectedHash, $capturedData['token_hash']);
    }

    public function testRawTokenIsNeverStoredInInsertData(): void
    {
        $capturedData = null;

        $mockModel = $this->createMock(AccessTokenModel::class);
        $mockModel->method('skipValidation')->willReturnSelf();
        $mockModel->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $service = new TokenService($mockModel, config('Kermesse'));
        $result  = $service->issueOwnerValidationToken(1, 1, 'test@example.com');

        // Raw token must not appear in the data sent to the DB
        foreach ($capturedData as $value) {
            $this->assertNotSame($result->rawToken, $value, 'Raw token must not be stored');
        }
    }

    public function testTokenTypeIsOwnerValidation(): void
    {
        $capturedData = null;

        $mockModel = $this->createMock(AccessTokenModel::class);
        $mockModel->method('skipValidation')->willReturnSelf();
        $mockModel->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $service = new TokenService($mockModel, config('Kermesse'));
        $service->issueOwnerValidationToken(5, 10, 'a@b.com');

        $this->assertSame('owner_validation', $capturedData['token_type']);
        $this->assertSame(5, $capturedData['owner_id']);
        $this->assertSame(10, $capturedData['kermesse_id']);
    }

    // ------------------------------------------------------------------
    // validateOwnerToken
    // ------------------------------------------------------------------

    /**
     * Build a mock AccessTokenModel with 'where' added via addMethods
     * (it's a builder proxy via __call, not a real method on the Model class).
     */
    private function buildTokenModelMock(?array $firstReturn = null): AccessTokenModel
    {
        $mock = $this->getMockBuilder(AccessTokenModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where'])
            ->onlyMethods(['first', 'skipValidation', 'insert', 'set', 'update'])
            ->getMock();
        $mock->method('where')->willReturnSelf();
        $mock->method('first')->willReturn($firstReturn);
        $mock->method('skipValidation')->willReturnSelf();
        $mock->method('insert')->willReturn(1);
        $mock->method('update')->willReturn(true);
        return $mock;
    }

    public function testValidateOwnerTokenReturnsInvalidWhenNotFound(): void
    {
        $mockModel = $this->buildTokenModelMock(null);

        $service = new TokenService($mockModel, config('Kermesse'));
        $result  = $service->validateOwnerToken('non-existent-token');

        $this->assertSame(TokenValidationResult::INVALID_TOKEN, $result->status);
        $this->assertFalse($result->isValid());
    }

    public function testValidateOwnerTokenReturnsRevokedForRevokedRow(): void
    {
        $mockModel = $this->buildTokenModelMock([
            'id'         => 1,
            'revoked_at' => '2025-01-01 00:00:00',
            'used_at'    => null,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $service = new TokenService($mockModel, config('Kermesse'));
        $result  = $service->validateOwnerToken('any-token');

        $this->assertSame(TokenValidationResult::REVOKED_TOKEN, $result->status);
        $this->assertNotNull($result->tokenRow);
    }

    public function testValidateOwnerTokenReturnsUsedForUsedRow(): void
    {
        $mockModel = $this->buildTokenModelMock([
            'id'         => 2,
            'revoked_at' => null,
            'used_at'    => '2025-01-01 00:00:00',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $service = new TokenService($mockModel, config('Kermesse'));
        $result  = $service->validateOwnerToken('any-token');

        $this->assertSame(TokenValidationResult::USED_TOKEN, $result->status);
    }

    public function testValidateOwnerTokenReturnsExpiredForExpiredRow(): void
    {
        $mockModel = $this->buildTokenModelMock([
            'id'          => 3,
            'owner_id'    => 1,
            'kermesse_id' => 1,
            'revoked_at'  => null,
            'used_at'     => null,
            'expires_at'  => '2020-01-01 00:00:00', // in the past
        ]);

        $service = new TokenService($mockModel, config('Kermesse'));
        $result  = $service->validateOwnerToken('any-token');

        $this->assertSame(TokenValidationResult::EXPIRED_TOKEN, $result->status);
    }

    public function testValidateOwnerTokenReturnsValidForActiveRow(): void
    {
        $mockModel = $this->buildTokenModelMock([
            'id'         => 4,
            'owner_id'   => 1,
            'kermesse_id'=> 1,
            'revoked_at' => null,
            'used_at'    => null,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $service = new TokenService($mockModel, config('Kermesse'));
        $result  = $service->validateOwnerToken('any-token');

        $this->assertSame(TokenValidationResult::VALID, $result->status);
        $this->assertTrue($result->isValid());
        $this->assertNotNull($result->tokenRow);
    }

    public function testValidateOwnerTokenHashesInputBeforeQuery(): void
    {
        $capturedHash = null;
        $rawToken     = 'my-raw-token';

        $mockModel = $this->getMockBuilder(AccessTokenModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where'])
            ->onlyMethods(['first'])
            ->getMock();
        $mockModel->method('where')->willReturnCallback(
            function (string $field, $value) use (&$capturedHash, $mockModel) {
                if ($field === 'token_hash') {
                    $capturedHash = $value;
                }
                return $mockModel;
            }
        );
        $mockModel->method('first')->willReturn(null);

        $service = new TokenService($mockModel, config('Kermesse'));
        $service->validateOwnerToken($rawToken);

        $expectedHash = hash('sha256', $rawToken);
        $this->assertSame($expectedHash, $capturedHash);
        $this->assertNotSame($rawToken, $capturedHash, 'Raw token must not be used in the query');
    }

    // ------------------------------------------------------------------
    // markTokenAsUsed
    // ------------------------------------------------------------------

    public function testMarkTokenAsUsedSetsUsedAt(): void
    {
        $capturedData = null;

        $mockModel = $this->getMockBuilder(AccessTokenModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where', 'affectedRows'])
            ->onlyMethods(['set', 'update'])
            ->getMock();
        $mockModel->method('where')->willReturnSelf();
        $mockModel->method('set')->willReturnCallback(
            function (array $data) use (&$capturedData, $mockModel) {
                $capturedData = $data;
                return $mockModel;
            }
        );
        $mockModel->method('update')->willReturnCallback(
            function () {
                return true;
            }
        );
        $mockModel->method('affectedRows')->willReturn(1);

        $service = new TokenService($mockModel, config('Kermesse'));
        $service->markTokenAsUsed(42);

        $this->assertArrayHasKey('used_at', $capturedData);
        $this->assertNotNull($capturedData['used_at']);
    }

    public function testValidateOwnerTokenRejectsMissingScope(): void
    {
        $mockModel = $this->buildTokenModelMock([
            'id'          => 4,
            'owner_id'    => null,
            'kermesse_id' => null,
            'revoked_at'  => null,
            'used_at'     => null,
            'expires_at'  => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $service = new TokenService($mockModel, config('Kermesse'));
        $result  = $service->validateOwnerToken('any-token');

        $this->assertSame(TokenValidationResult::INVALID_TOKEN, $result->status);
    }

    public function testHasRecentActiveOwnerValidationTokenDetectsRecentToken(): void
    {
        $mockModel = $this->buildTokenModelMock(['id' => 1]);

        $service = new TokenService($mockModel, config('Kermesse'));

        $this->assertTrue($service->hasRecentActiveOwnerValidationToken(7, 300));
    }

    public function testIssueOwnerValidationTokenRejectsNonPositiveTtl(): void
    {
        $config = clone config('Kermesse');
        $config->ownerValidationTokenTTL = 0;

        $service = new TokenService($this->createMock(AccessTokenModel::class), $config);

        $this->expectException(\InvalidArgumentException::class);

        $service->issueOwnerValidationToken(1, 1, 'test@example.com');
    }

    // ------------------------------------------------------------------
    // revokeActiveOwnerValidationTokens
    // ------------------------------------------------------------------

    public function testValidateOwnerTokenFiltersTokenType(): void
    {
        $capturedFilters = [];

        $mockModel = $this->getMockBuilder(AccessTokenModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where'])
            ->onlyMethods(['first'])
            ->getMock();
        $mockModel->method('where')->willReturnCallback(
            function (string $field, $value) use (&$capturedFilters, $mockModel) {
                $capturedFilters[$field] = $value;
                return $mockModel;
            }
        );
        $mockModel->method('first')->willReturn(null);

        $service = new TokenService($mockModel, config('Kermesse'));
        $service->validateOwnerToken('any-raw-token');

        $this->assertArrayHasKey('token_type', $capturedFilters,
            'validateOwnerToken must filter by token_type');
        $this->assertSame('owner_validation', $capturedFilters['token_type'],
            'token_type filter must be owner_validation');
    }

    public function testMarkTokenAsUsedReturnsFalseWhenZeroAffectedRows(): void
    {
        $mockModel = $this->getMockBuilder(AccessTokenModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where', 'affectedRows'])
            ->onlyMethods(['set', 'update'])
            ->getMock();
        $mockModel->method('where')->willReturnSelf();
        $mockModel->method('set')->willReturnSelf();
        $mockModel->method('update')->willReturn(true);
        $mockModel->method('affectedRows')->willReturn(0);

        $service = new TokenService($mockModel, config('Kermesse'));
        $result  = $service->markTokenAsUsed(42);

        $this->assertFalse($result,
            'markTokenAsUsed must return false when no row is updated (concurrent claim)');
    }

    public function testValidateOwnerTokenTreatsExpiredAtNowAsExpired(): void
    {
        $mockModel = $this->buildTokenModelMock([
            'id'          => 10,
            'owner_id'    => 1,
            'kermesse_id' => 1,
            'revoked_at'  => null,
            'used_at'     => null,
            'expires_at'  => date('Y-m-d H:i:s', time()), // exactly now → expired (<=)
        ]);

        $service = new TokenService($mockModel, config('Kermesse'));
        $result  = $service->validateOwnerToken('any-token');

        $this->assertSame(TokenValidationResult::EXPIRED_TOKEN, $result->status,
            'A token expiring at the current second must be treated as expired');
    }

    public function testRevokeActiveOwnerValidationTokensSetsRevokedAt(): void
    {
        $capturedSetData = null;

        // 'where' is a builder proxy via __call (addMethods); 'set' is a real declared method (onlyMethods)
        $mockModel = $this->getMockBuilder(AccessTokenModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where'])
            ->onlyMethods(['set', 'update'])
            ->getMock();
        $mockModel->method('where')->willReturnSelf();
        $mockModel->method('set')->willReturnCallback(
            function (array $data) use (&$capturedSetData, $mockModel) {
                $capturedSetData = $data;
                return $mockModel;
            }
        );
        $mockModel->method('update')->willReturn(true);

        $service = new TokenService($mockModel, config('Kermesse'));
        $service->revokeActiveOwnerValidationTokens(7);

        $this->assertArrayHasKey('revoked_at', $capturedSetData);
        $this->assertNotNull($capturedSetData['revoked_at']);
    }
}

<?php

use CodeIgniter\Test\CIUnitTestCase;
use App\Services\SignupService;
use App\Services\SignupResult;
use App\Models\VolunteerModel;
use App\Models\SignupModel;

/**
 * Unit tests for SignupService — volunteer creation/reuse and signup insertion (Story 3.3).
 *
 * @internal
 */
final class SignupServiceTest extends CIUnitTestCase
{
    private function buildService(
        ?VolunteerModel $volunteerModel = null,
        ?SignupModel    $signupModel    = null,
    ): SignupService {
        return new SignupService(
            $volunteerModel ?? $this->buildMockVolunteerModel(),
            $signupModel    ?? $this->buildMockSignupModel(),
        );
    }

    private function buildMockVolunteerModel(int $returnedId = 42): VolunteerModel
    {
        $mock = $this->getMockBuilder(VolunteerModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByKermesseAndEmail', 'skipValidation', 'insert'])
            ->getMock();
        $mock->method('findByKermesseAndEmail')->willReturn(null);
        $mock->method('skipValidation')->willReturnSelf();
        $mock->method('insert')->willReturn($returnedId);
        return $mock;
    }

    private function buildMockSignupModel(int $returnedId = 99): SignupModel
    {
        $mock = $this->createMock(SignupModel::class);
        $mock->method('skipValidation')->willReturnSelf();
        $mock->method('insert')->willReturn($returnedId);
        return $mock;
    }

    private function validFields(): array
    {
        return [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'marie@exemple.fr',
            'phone'      => '0612345678',
        ];
    }

    // ------------------------------------------------------------------
    // AC1 — New email → creates volunteer + signup
    // ------------------------------------------------------------------

    public function testNewEmailCreatesVolunteerAndReturnsSuccess(): void
    {
        $volunteerMock = $this->buildMockVolunteerModel(42);
        $volunteerMock->method('findByKermesseAndEmail')->willReturn(null);
        $volunteerMock->expects($this->once())->method('insert');

        $signupMock = $this->buildMockSignupModel(99);
        $signupMock->expects($this->once())->method('insert');

        $service = $this->buildService($volunteerMock, $signupMock);
        $result  = $service->signup(1, 10, $this->validFields());

        $this->assertInstanceOf(SignupResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame(99, $result->signupId);
    }

    // ------------------------------------------------------------------
    // AC2 — Existing email for same kermesse → reuse volunteer
    // ------------------------------------------------------------------

    public function testExistingEmailReusesVolunteerWithoutCreating(): void
    {
        $existingVolunteer = ['id' => 7, 'email' => 'marie@exemple.fr', 'kermesse_id' => 10];

        $volunteerMock = $this->getMockBuilder(VolunteerModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByKermesseAndEmail', 'skipValidation', 'insert'])
            ->getMock();
        $volunteerMock->method('findByKermesseAndEmail')->willReturn($existingVolunteer);
        $volunteerMock->expects($this->never())->method('insert');

        $signupMock = $this->buildMockSignupModel(100);
        $signupMock->expects($this->once())->method('insert');

        $service = $this->buildService($volunteerMock, $signupMock);
        $result  = $service->signup(1, 10, $this->validFields());

        $this->assertTrue($result->success);
        $this->assertSame(7, $result->volunteerId);
    }

    // ------------------------------------------------------------------
    // AC3 — Email normalization: casing treated identically
    // ------------------------------------------------------------------

    public function testEmailIsNormalizedBeforeLookup(): void
    {
        $capturedEmail = null;

        $volunteerMock = $this->getMockBuilder(VolunteerModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByKermesseAndEmail', 'skipValidation', 'insert'])
            ->getMock();
        $volunteerMock->method('findByKermesseAndEmail')
            ->willReturnCallback(function (int $kermesseId, string $email) use (&$capturedEmail) {
                $capturedEmail = $email;
                return null;
            });
        $volunteerMock->method('skipValidation')->willReturnSelf();
        $volunteerMock->method('insert')->willReturn(1);

        $fields = array_merge($this->validFields(), ['email' => '  TEST@EXAMPLE.COM  ']);

        $service = $this->buildService($volunteerMock, $this->buildMockSignupModel());
        $service->signup(1, 10, $fields);

        $this->assertSame('test@example.com', $capturedEmail, 'Email must be lowercased and trimmed before lookup');
    }

    public function testNormalizedEmailStoredOnNewVolunteer(): void
    {
        $capturedData = null;

        $volunteerMock = $this->getMockBuilder(VolunteerModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByKermesseAndEmail', 'skipValidation', 'insert'])
            ->getMock();
        $volunteerMock->method('findByKermesseAndEmail')->willReturn(null);
        $volunteerMock->method('skipValidation')->willReturnSelf();
        $volunteerMock->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $fields = array_merge($this->validFields(), ['email' => 'Marie@Example.FR']);

        $service = $this->buildService($volunteerMock, $this->buildMockSignupModel());
        $service->signup(1, 10, $fields);

        $this->assertSame('marie@example.fr', $capturedData['email'] ?? null);
    }

    // ------------------------------------------------------------------
    // Signup row carries the correct volunteer_id and slot_id
    // ------------------------------------------------------------------

    public function testSignupInsertedWithCorrectVolunteerIdAndSlotId(): void
    {
        $capturedSignup = null;

        $volunteerMock = $this->buildMockVolunteerModel(55);
        $volunteerMock->method('findByKermesseAndEmail')->willReturn(null);

        $signupMock = $this->getMockBuilder(SignupModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['skipValidation', 'insert'])
            ->getMock();
        $signupMock->method('skipValidation')->willReturnSelf();
        $signupMock->method('insert')->willReturnCallback(function (array $data) use (&$capturedSignup) {
            $capturedSignup = $data;
            return 77;
        });

        $service = $this->buildService($volunteerMock, $signupMock);
        $service->signup(slotId: 3, kermesseId: 10, fields: $this->validFields());

        $this->assertSame(3,  $capturedSignup['slot_id'] ?? null);
        $this->assertSame(55, $capturedSignup['volunteer_id'] ?? null);
        $this->assertSame('active', $capturedSignup['status'] ?? null);
    }
}

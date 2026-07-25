<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Validator;

use JsonRPC\Exception\AuthenticationFailureException;
use JsonRPC\Validator\UserValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserValidator::class)]
final class UserValidatorTest extends TestCase
{
    private UserValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new UserValidator();
    }

    public function testAllowsEveryoneWhenNoUserIsConfigured(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate([], null, null);
    }

    public function testAllowsAKnownUser(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate(['user' => 'pass'], 'user', 'pass');
    }

    public function testAllowsCredentialsMadeOfTheStringZero(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate(['0' => '0'], '0', '0');
    }

    public function testAllowsAnEmptyPasswordWhenThatIsWhatWasConfigured(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate(['user' => ''], 'user', '');
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string|null, string|null}>
     */
    public static function rejectedCredentials(): array
    {
        return [
            'wrong password' => [['user' => 'pass'], 'user', 'wrong'],
            'unknown user' => [['user' => 'pass'], 'nobody', 'pass'],
            'missing username' => [['user' => 'pass'], null, 'pass'],
            'missing password' => [['user' => 'pass'], 'user', null],
            'missing password against an empty one' => [['user' => ''], 'user', null],
            'non string stored password' => [['user' => false], 'user', ''],
            'stored boolean true' => [['user' => true], 'user', '1'],
            'stored integer' => [['user' => 0], 'user', '0'],
        ];
    }

    /**
     * @param array<array-key, mixed> $users
     */
    #[DataProvider('rejectedCredentials')]
    public function testRejectsEverythingElse(array $users, ?string $username, ?string $password): void
    {
        $this->expectException(AuthenticationFailureException::class);
        $this->expectExceptionMessage('Access not allowed');

        $this->validator->validate($users, $username, $password);
    }
}

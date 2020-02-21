<?php

namespace SymfonyCasts\Bundle\ResetPassword;

use SymfonyCasts\Bundle\ResetPassword\Exception\ExpiredResetPasswordTokenException;
use SymfonyCasts\Bundle\ResetPassword\Exception\InvalidResetPasswordTokenException;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\Generator\ResetPasswordTokenGenerator;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

/**
 * @author Jesse Rushlow <jr@rushlow.dev>
 * @author Ryan Weaver <ryan@symfonycasts.com>
 */
class ResetPasswordHelper implements ResetPasswordHelperInterface
{
    /**
     * The first 20 characters of the token are a "selector"
     */
    private const SELECTOR_LENGTH = 20;

    /**
     * @var ResetPasswordTokenGenerator
     */
    private $tokenGenerator;

    /**
     * @var ResetPasswordRequestRepositoryInterface
     */
    private $repository;

    /**
     * @var int How long a token is valid in seconds
     */
    private $resetRequestLifetime;

    /**
     * @var int Another password reset cannot be made faster than this throttle time in seconds.
     */
    private $requestThrottleTime;

    public function __construct(ResetPasswordTokenGenerator $generator, ResetPasswordRequestRepositoryInterface $repository, int $resetRequestLifetime, int $requestThrottleTime)
    {
        $this->tokenGenerator = $generator;
        $this->repository = $repository;
        $this->resetRequestLifetime = $resetRequestLifetime;
        $this->requestThrottleTime = $requestThrottleTime;
    }

    /**
     * Generates a new password reset token, persists it & returns the token that can be emailed to the user.
     *
     * Some of the cryptographic strategies were taken from
     * https://paragonie.com/blog/2017/02/split-tokens-token-based-authentication-protocols-without-side-channels
     *
     * @throws TooManyPasswordRequestsException
     */
    public function generateResetToken(object $user): ResetPasswordToken
    {
        if ($this->hasUserHisThrottling($user)) {
            throw new TooManyPasswordRequestsException();
        }

        $expiresAt = (new \DateTimeImmutable('now'))
            ->modify(sprintf('+%d seconds', $this->resetRequestLifetime))
        ;

        $tokenComponents = $this->tokenGenerator->createToken($expiresAt, $this->repository->getUserIdentifier($user));

        $passwordResetRequest = $this->repository->createResetPasswordRequest(
            $user,
            $expiresAt,
            $tokenComponents->getSelector(),
            $tokenComponents->getHashedToken()
        );

        $this->repository->persistResetPasswordRequest($passwordResetRequest);

        // final "public" token is the selector + non-hashed verifier token
        return new ResetPasswordToken(
            $tokenComponents->getPublicToken(),
            $expiresAt
        );
    }

    /**
     * Validate a PasswordResetRequest and fetch user from persistence
     *
     * @param string $fullToken selector + non-hashed verifier token
     * @throws ExpiredResetPasswordTokenException
     * @throws InvalidResetPasswordTokenException
     */
    public function validateTokenAndFetchUser(string $fullToken): object
    {
        $resetRequest = $this->findResetPasswordRequest($fullToken);

        if (null === $resetRequest) {
            throw new InvalidResetPasswordTokenException();
        }

        if ($resetRequest->isExpired()) {
            throw new ExpiredResetPasswordTokenException();
        }

        $user = $resetRequest->getUser();

        $hashedVerifierToken = $this->tokenGenerator->createToken(
            $resetRequest->getExpiresAt(),
            $this->repository->getUserIdentifier($user),
            substr($fullToken, self::SELECTOR_LENGTH)
        );

        if (false === hash_equals($resetRequest->getHashedToken(), $hashedVerifierToken->getHashedToken())) {
            throw new InvalidResetPasswordTokenException();
        }

        return $user;
    }

    /**
     * Remove a single PasswordResetRequest object from persistence
     *
     * @param string $fullToken selector + non-hashed verifier token
     * @throws InvalidResetPasswordTokenException
     */
    public function removeResetRequest(string $fullToken): void
    {
        $request = $this->findResetPasswordRequest($fullToken);

        if (null === $request) {
            throw new InvalidResetPasswordTokenException();
        }

        $this->repository->removeResetPasswordRequest($request);
    }

    private function findResetPasswordRequest(string $token): ?ResetPasswordRequestInterface
    {
        $selector = substr($token, 0, self::SELECTOR_LENGTH);

        return $this->repository->findResetPasswordRequest($selector);
    }

    private function hasUserHisThrottling(object $user): bool
    {
        $lastRequestDate = $this->repository->getMostRecentNonExpiredRequestDate($user);

        if (null === $lastRequestDate) {
            return false;
        }

        if (($lastRequestDate->getTimestamp() + $this->requestThrottleTime) > time()) {
            return true;
        }

        return false;
    }
}
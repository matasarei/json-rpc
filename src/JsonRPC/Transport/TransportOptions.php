<?php

declare(strict_types=1);

namespace JsonRPC\Transport;

/**
 * Connection settings shared by the built-in transports.
 */
final readonly class TransportOptions
{
    /**
     * @param int $connectTimeout Seconds to wait for the connection to be established
     * @param int $transferTimeout Seconds allowed for the whole transfer, 0 for no limit
     * @param string|null $caFile Certificate authority bundle used to verify the server
     * @param string|null $localCert Client certificate sent to the server
     * @param array<int|string, mixed> $extraOptions Raw cURL options or stream context overrides
     */
    public function __construct(
        public int $connectTimeout = 5,
        public int $transferTimeout = 0,
        public bool $verifySsl = true,
        public ?string $caFile = null,
        public ?string $localCert = null,
        public array $extraOptions = [],
    ) {
    }

    public function withConnectTimeout(int $seconds): self
    {
        return new self(
            $seconds,
            $this->transferTimeout,
            $this->verifySsl,
            $this->caFile,
            $this->localCert,
            $this->extraOptions,
        );
    }

    public function withTransferTimeout(int $seconds): self
    {
        return new self(
            $this->connectTimeout,
            $seconds,
            $this->verifySsl,
            $this->caFile,
            $this->localCert,
            $this->extraOptions,
        );
    }

    public function withSslVerification(bool $verify): self
    {
        return new self(
            $this->connectTimeout,
            $this->transferTimeout,
            $verify,
            $this->caFile,
            $this->localCert,
            $this->extraOptions,
        );
    }

    public function withCaFile(string $path): self
    {
        return new self(
            $this->connectTimeout,
            $this->transferTimeout,
            $this->verifySsl,
            $path,
            $this->localCert,
            $this->extraOptions,
        );
    }

    public function withLocalCert(string $path): self
    {
        return new self(
            $this->connectTimeout,
            $this->transferTimeout,
            $this->verifySsl,
            $this->caFile,
            $path,
            $this->extraOptions,
        );
    }

    /**
     * @param array<int|string, mixed> $options Merged over the options set so far
     */
    public function withExtraOptions(array $options): self
    {
        return new self(
            $this->connectTimeout,
            $this->transferTimeout,
            $this->verifySsl,
            $this->caFile,
            $this->localCert,
            array_replace($this->extraOptions, $options),
        );
    }
}

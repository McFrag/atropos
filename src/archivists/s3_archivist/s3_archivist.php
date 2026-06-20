<?php

/**
 * S3-backed cold storage. Requires the AWS SDK for PHP
 * (aws/aws-sdk-php) to be available via composer autoload.
 *
 * Unlike fs_archivist, PutObject is atomic at the object level -- there
 * is no partial-write visibility window, so no lock/retry logic is
 * needed on store().
 */
class s3_archivist extends archivist
{
    /** @var \Aws\S3\S3Client */
    private $client;

    /** @var string */
    private $bucket;

    /** @var string */
    private $prefix;

    public function init($json_file)
    {
        $options = json_decode(file_get_contents($json_file), true);

        $this->bucket = $options['bucket'];
        $this->prefix = isset($options['prefix']) ? trim($options['prefix'], '/') : '';

        $this->client = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region'  => $options['region'],
            // Falls back to default credential provider chain (env vars,
            // instance role, shared credentials file) if not specified.
            'credentials' => isset($options['credentials']) ? $options['credentials'] : null,
        ]);
    }

    public function fetch($hash, $size)
    {
        $key = $this->key_from_info($hash, $size);

        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);
        } catch (\Aws\S3\Exception\S3Exception $e) {
            return new fetch_result(1, "S3 fetch failed for key {$key}: " . $e->getMessage());
        }

        $data = (string) $result['Body'];

        if (strlen($data) !== $size) {
            return new fetch_result(2, "size mismatch fetching S3 key {$key}");
        }

        return new fetch_result(0, $data);
    }

    public function store($data)
    {
        $key = $this->key_from_data($data);

        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
                'Body'   => $data,
            ]);
        } catch (\Aws\S3\Exception\S3Exception $e) {
            return new op_result(1, "S3 store failed for key {$key}: " . $e->getMessage());
        }

        return new op_result(0, null);
    }

    protected function key_from_info($hash, $size)
    {
        $size_hex = sprintf('%08x', $size);
        $key = "{$hash}-{$size_hex}";

        return $this->prefix !== '' ? "{$this->prefix}/{$key}" : $key;
    }
}

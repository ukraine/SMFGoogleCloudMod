<?php

/**
 * Google Cloud Storage client.
 */
class GstorageClient
{
    protected $client;
    protected $service;
    protected $key;
    protected $serviceAccountName;

    public function __construct()
    {
        global $modSettings;

        // Setup client with SMF settings.
        $this->serviceAccountName = $modSettings['gsServiceAccountName'];
        $this->key = $modSettings['gsKey'];
        if (!$this->serviceAccountName || !$this->key)
            throw new Exception('Google Cloud Plugin is not configurated!');
        $this->key = base64_decode($this->key);

        $this->client = $client = new Google_Client();
        $client->setApplicationName('SMF attachmets plugin');
        $this->service = $service = new Google_Service_Storage($client);
        if (isset($_SESSION['service_token'])) {
            $client->setAccessToken($_SESSION['service_token']);
        }
        $cred = new Google_Auth_AssertionCredentials(
            $this->serviceAccountName,
            array('https://www.googleapis.com/auth/devstorage.full_control'),
            $this->key
        );
        $client->setAssertionCredentials($cred);
        if ($client->getAuth()->isAccessTokenExpired()) {
            $client->getAuth()->refreshTokenWithAssertion($cred);
        }
        $_SESSION['service_token'] = $client->getAccessToken();
    }

    /**
     * @return void
     */
    public function upload($bucket, $path, $data)
    {
        $path = trim($path, '/');
        $obj = new Google_Service_Storage_StorageObject();
        $obj->setName($path);
        $this->service->objects->insert(
            $bucket,
            $obj,
            array(
                'name' => $path,
                'data' => $data,
                'uploadType' => 'media'
            )
        );
    }

    /**
     * @return string
     */
    public function download($bucket, $path)
    {
        $path = trim($path, '/');
        $obj = $this->service->objects->get($bucket, $path);
        $request = new Google_Http_Request($obj->getMediaLink());
        $response = $this->client->getAuth()->authenticatedRequest($request);
        if ($response->getResponseHttpCode() != 200)
            throw new Exception('Error request');
        return $response->getResponseBody();
    }

    /**
     * @return void
     */
    public function remove($bucket, $path)
    {
        $path = trim($path, '/');
        $this->service->objects->delete($bucket, $path);
    }

    /**
     * @return void
     */
    public function copy($bucket, $path, $toBucket, $toPath)
    {
        $path = trim($path, '/');
        $toPath = trim($toPath, '/');
        $obj = new Google_Service_Storage_StorageObject();
        $this->service->objects->copy($bucket, $path, $toBucket, $toPath, $obj);
    }

    /**
     * @return void
     */
    public function rename($bucket, $path, $toBucket, $toPath)
    {
        $path = trim($path, '/');
        $toPath = trim($toPath, '/');
        $this->copy($bucket, $path, $toBucket, $toPath);
        $this->remove($bucket, $path);
    }

    /**
     * @return string
     */
    public function createSignedLinkFromPath($path, $contentDisposition = null)
    {
        $options = parse_url($path);
        $bucket = $options['host'];
        $path = trim(isset($options['path']) ? $options['path'] : '' , '/');
        return $this->createSignedLink($bucket, $path, $contentDisposition);
    }

    /**
     * @return string
     */
    public function createSignedLink($bucket, $path, $contentDisposition = null)
    {
        $path = trim($path, '/');
        $ttl = time() + 1800;
        $signer = new Google_Signer_P12($this->key, 'notasecret');
        $stringToSign = "GET\n" . "\n" . "\n" . $ttl . "\n". '/' . $bucket . '/' . $path;
        $signature = $signer->sign(utf8_encode($stringToSign));
        $finalSignature = Google_Utils::urlSafeB64Encode($signature);
        $host = 'https://' . $bucket . '.storage.googleapis.com';
        return $host . '/' . $path . '?Expires=' . $ttl . '&GoogleAccessId='
            . urlencode($this->serviceAccountName)
            . ($contentDisposition ? '&response-content-disposition=' . urlencode($contentDisposition) : '')
            . '&Signature='
            . str_replace(array('-','_',), array('%2B', '%2F'), urlencode($finalSignature)) . '%3D';
    }

    /**
     * @return bool
     */
    public function isBucketExists($bucket)
    {
        try {
            $bucket = $this->service->buckets->get($bucket);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @return array
     */
    public function listDir($bucket, $path)
    {
        $path = trim($path, '/');
        if ($path)
            $path .= '/';
        $objects = $this->getObjectsByPrefix($bucket, $path);
        $res = array();
        // "files"
        foreach ($objects->getItems() as $obj) {
            $res[] = sprintf('gs://%s/%s', $obj->getBucket(), $obj->getName());
        }
        // "folders"
        if ($objects->getPrefixes())
            foreach ($objects->getPrefixes() as $prefix)
                $res[] = sprintf('gs://%s/%s', $bucket, $prefix);

        return $res;
    }

    /**
     * @return Google_Service_Storage_StorageObject|string
     * @see GstorageStreamWrapper::url_stat()
     */
    public function getObjectOrDir($bucket, $path)
    {
        $path = trim($path, '/');
        // Return root on empty path.
        if (!$path) {
            if (!$this->isBucketExists($bucket))
                throw new Exception('Bucket not found');
            return '/';
        }
        // ...otherwise search for folder or file.
        $objects = $this->getObjectsByPrefix($bucket, $path);
        // "folders"
        if ($objects->getPrefixes())
            foreach ($objects->getPrefixes() as $prefix)
                if (trim($prefix, '/') == $path)
                    return sprintf('gs://%s/%s', $bucket, $prefix);
        // "files"
        foreach ($objects->getItems() as $obj)
            if ($obj->getName() == $path)
                return $obj;

        return new Google_Service_Storage_StorageObject();
    }

    protected function getObjectsByPrefix($bucket, $prefix)
    {
        $options = array(
            'delimiter' => '/',
            'pageToken' => '',
            'prefix' => $prefix,
        );
        // TODO: pagination with pageToken.
        $objects = $this->service->objects->listObjects($bucket, $options);
        return $objects;
    }
}

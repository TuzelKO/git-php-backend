<?php

namespace GitPHP;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;

class Backend {

    private $cmd = "/usr/lib/git-core/git-http-backend";

    private $environment;

    private $request;

    private $stdout;
    private $stderr;

    public $headers;
    public $body;
    public $status;

    private $timeTaken;

    private $logger;

    /**
     * PHPGitBackend constructor.
     * @param string $projectsDir
     * @param string|null $url
     * @param string $userName
     * @param string $userEmail
     */
    function __construct($projectsDir, $url = null, $userName = "user", $userEmail = "user@example.com") {

        $this->request = file_get_contents("php://input");

        $requestUrl = (empty($_SERVER['REQUEST_URI']))? null:strtok($_SERVER["REQUEST_URI"],'?');
        $requestUrl = (empty($url))? $requestUrl:strtok($_SERVER["REQUEST_URI"],'?');

        $remoteAddr = (empty($_SERVER['REMOTE_ADDR']))? null:$_SERVER['REMOTE_ADDR'];
        $queryString = (empty($_SERVER['QUERY_STRING']))? null:$_SERVER['QUERY_STRING'];
        $requestMethod = (empty($_SERVER['REQUEST_METHOD']))? null:$_SERVER['REQUEST_METHOD'];
        $contentType = (empty($_SERVER['CONTENT_TYPE']))? null:$_SERVER['CONTENT_TYPE'];
        $contentLength = (empty($_SERVER['CONTENT_LENGTH']))? null:$_SERVER['CONTENT_LENGTH'];

        $this->environment = [
            'GIT_HTTP_EXPORT_ALL' => true,
            'GIT_PROJECT_ROOT' => $projectsDir,
            'PATH_INFO' => $requestUrl,
            'REMOTE_USER' => $userName,
            'REMOTE_ADDR' => $remoteAddr,
            'QUERY_STRING' => $queryString,
            'REQUEST_METHOD' => $requestMethod,
            'CONTENT_TYPE' => $contentType,
            'CONTENT_LENGTH' => $contentLength,
		    'GIT_COMMITTER_NAME' => $userName,
		    'GIT_COMMITTER_EMAIL' => $userEmail
        ];

        return $this;

    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    /**
     * @return Logger
     */
    private function getLogger() {

        if (!isset($this->logger)) {
            $this->logger = new Logger("Git-Backend");
            $this->logger->pushHandler(new StreamHandler('php://stderr', Logger::NOTICE));
        }

        return $this->logger;

    }

    /**
     * @param string $cmd
     */
    public function setCmd(string $cmd) {
        $this->cmd = $cmd;
    }

    /**
     * @param bool $send
     * @return int
     */
    public function exec($send = true){

        $this->timeTaken = microtime(true);

        $pipes = [];
        $desc = [
            0 => ['pipe', 'r'], // STDIN
            1 => ['pipe', 'w'], // STDOUT
            2 => ['pipe', 'w']  // STDERR
        ];

        $proc = proc_open($this->cmd, $desc, $pipes, null, $this->environment);

        if(!empty($this->request)) fwrite($pipes[0], $this->request);

        $this->stdout = stream_get_contents($pipes[1]);
        $this->stderr = stream_get_contents($pipes[2]);

        if($this->stderr) $this->getLogger()->notice($this->stderr);

        foreach($pipes as $pipe) fclose($pipe);

        $this->timeTaken = microtime(true) - $this->timeTaken;

        list($this->headers, $this->body, $this->status) =
            array_pad(explode("\r\n\r\n", $this->stdout, 2), 3, '');

        $this->status =
            preg_match('/Status: (\\d\\d\\d)/', $this->headers, $this->status)? $this->status[1]:null;

        $this->headers = explode("\r\n", $this->headers);

        if($send === true){

            foreach($this->headers as $header) header($header, true, $this->status);
            echo $this->body;

        }

        return proc_close($proc);

    }

}

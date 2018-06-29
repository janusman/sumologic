<?php

namespace Drutiny\SumoLogic;

use Drutiny\Container;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Output\ConsoleOutput;

class RunningQuery {

  protected $job;
  protected $client;
  protected $status;
  protected $cache;
  protected $item;
  protected $successCallback;

  public function __construct($job, Client $client, CacheItemPoolInterface $cache, CacheItemInterface $item)
  {
    $this->job = $job;
    $this->client = $client;
    $this->status = Client::QUERY_JOB_NOT_STARTED;
    $this->successCallback = function ($records) {};
    $this->cache = $cache;
    $this->item = $item;
  }

  public function onSuccess(callable $callback)
  {
    $this->successCallback = $callback;
    return $this;
  }

  public function wait()
  {
    if (!$this->item->isHit()) {
      $attempt = 0;
      while ($this->status < Client::QUERY_COMPLETE) {
        if ($attempt >= 200) {
          throw new \Exception("Sumologic query took too long. Quit waiting.");
        }
        sleep(3);
        $this->status = $this->client->queryStatus($this->job->id);
        Container::getLogger()->info(__CLASS__ . ": Job {$this->job->id} status: {$this->status}");
        $attempt++;
      }

      // Query completed, lets acquire the rows.
      $records = $this->client->fetchRecords($this->job->id);
      $this->item->set($records)->expiresAt(new \DateTime('+1 month'));
      $this->cache->save($this->item);
    }

    return call_user_func($this->successCallback, $this->item->get());
  }

  public function __destruct()
  {
    !empty($this->job) && $this->client->deleteQuery($this->job->id);
  }

}

 ?>

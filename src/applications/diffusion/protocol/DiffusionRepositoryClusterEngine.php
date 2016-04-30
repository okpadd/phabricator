<?php

/**
 * Manages repository synchronization for cluster repositories.
 *
 * @task config Configuring Synchronization
 * @task sync Cluster Synchronization
 * @task internal Internals
 */
final class DiffusionRepositoryClusterEngine extends Phobject {

  private $repository;
  private $viewer;
  private $logger;

  private $clusterWriteLock;
  private $clusterWriteVersion;
  private $clusterWriteOwner;


/* -(  Configuring Synchronization  )---------------------------------------- */


  public function setRepository(PhabricatorRepository $repository) {
    $this->repository = $repository;
    return $this;
  }

  public function getRepository() {
    return $this->repository;
  }

  public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function getViewer() {
    return $this->viewer;
  }

  public function setLog(DiffusionRepositoryClusterEngineLogInterface $log) {
    $this->logger = $log;
    return $this;
  }


/* -(  Cluster Synchronization  )-------------------------------------------- */


  /**
   * Synchronize repository version information after creating a repository.
   *
   * This initializes working copy versions for all currently bound devices to
   * 0, so that we don't get stuck making an ambiguous choice about which
   * devices are leaders when we later synchronize before a read.
   *
   * @task sync
   */
  public function synchronizeWorkingCopyAfterCreation() {
    if (!$this->shouldEnableSynchronization()) {
      return;
    }

    $repository = $this->getRepository();
    $repository_phid = $repository->getPHID();

    $service = $repository->loadAlmanacService();
    if (!$service) {
      throw new Exception(pht('Failed to load repository cluster service.'));
    }

    $bindings = $service->getActiveBindings();
    foreach ($bindings as $binding) {
      PhabricatorRepositoryWorkingCopyVersion::updateVersion(
        $repository_phid,
        $binding->getDevicePHID(),
        0);
    }

    return $this;
  }


  /**
   * @task sync
   */
  public function synchronizeWorkingCopyBeforeRead() {
    if (!$this->shouldEnableSynchronization()) {
      return;
    }

    $repository = $this->getRepository();
    $repository_phid = $repository->getPHID();

    $device = AlmanacKeys::getLiveDevice();
    $device_phid = $device->getPHID();

    $read_lock = PhabricatorRepositoryWorkingCopyVersion::getReadLock(
      $repository_phid,
      $device_phid);

    $lock_wait = phutil_units('2 minutes in seconds');

    $this->logLine(
      pht(
        'Waiting up to %s second(s) for a cluster read lock on "%s"...',
        new PhutilNumber($lock_wait),
        $device->getName()));

    try {
      $start = PhabricatorTime::getNow();
      $read_lock->lock($lock_wait);
      $waited = (PhabricatorTime::getNow() - $start);

      if ($waited) {
        $this->logLine(
          pht(
            'Acquired read lock after %s second(s).',
            new PhutilNumber($waited)));
      } else {
        $this->logLine(
          pht(
            'Acquired read lock immediately.'));
      }
    } catch (Exception $ex) {
      throw new PhutilProxyException(
        pht(
          'Failed to acquire read lock after waiting %s second(s). You '.
          'may be able to retry later.',
          new PhutilNumber($lock_wait)));
    }

    $versions = PhabricatorRepositoryWorkingCopyVersion::loadVersions(
      $repository_phid);
    $versions = mpull($versions, null, 'getDevicePHID');

    $this_version = idx($versions, $device_phid);
    if ($this_version) {
      $this_version = (int)$this_version->getRepositoryVersion();
    } else {
      $this_version = -1;
    }

    if ($versions) {
      // This is the normal case, where we have some version information and
      // can identify which nodes are leaders. If the current node is not a
      // leader, we want to fetch from a leader and then update our version.

      $max_version = (int)max(mpull($versions, 'getRepositoryVersion'));
      if ($max_version > $this_version) {
        $fetchable = array();
        foreach ($versions as $version) {
          if ($version->getRepositoryVersion() == $max_version) {
            $fetchable[] = $version->getDevicePHID();
          }
        }

        $this->synchronizeWorkingCopyFromDevices($fetchable);

        PhabricatorRepositoryWorkingCopyVersion::updateVersion(
          $repository_phid,
          $device_phid,
          $max_version);
      } else {
        $this->logLine(
          pht(
            'Device "%s" is already a cluster leader and does not need '.
            'to be synchronized.',
            $device->getName()));
      }

      $result_version = $max_version;
    } else {
      // If no version records exist yet, we need to be careful, because we
      // can not tell which nodes are leaders.

      // There might be several nodes with arbitrary existing data, and we have
      // no way to tell which one has the "right" data. If we pick wrong, we
      // might erase some or all of the data in the repository.

      // Since this is dangeorus, we refuse to guess unless there is only one
      // device. If we're the only device in the group, we obviously must be
      // a leader.

      $service = $repository->loadAlmanacService();
      if (!$service) {
        throw new Exception(pht('Failed to load repository cluster service.'));
      }

      $bindings = $service->getActiveBindings();
      $device_map = array();
      foreach ($bindings as $binding) {
        $device_map[$binding->getDevicePHID()] = true;
      }

      if (count($device_map) > 1) {
        throw new Exception(
          pht(
            'Repository "%s" exists on more than one device, but no device '.
            'has any repository version information. Phabricator can not '.
            'guess which copy of the existing data is authoritative. Remove '.
            'all but one device from service to mark the remaining device '.
            'as the authority.',
            $repository->getDisplayName()));
      }

      if (empty($device_map[$device->getPHID()])) {
        throw new Exception(
          pht(
            'Repository "%s" is being synchronized on device "%s", but '.
            'this device is not bound to the corresponding cluster '.
            'service ("%s").',
            $repository->getDisplayName(),
            $device->getName(),
            $service->getName()));
      }

      // The current device is the only device in service, so it must be a
      // leader. We can safely have any future nodes which come online read
      // from it.
      PhabricatorRepositoryWorkingCopyVersion::updateVersion(
        $repository_phid,
        $device_phid,
        0);

      $result_version = 0;
    }

    $read_lock->unlock();

    return $result_version;
  }


  /**
   * @task sync
   */
  public function synchronizeWorkingCopyBeforeWrite() {
    if (!$this->shouldEnableSynchronization()) {
      return;
    }

    $repository = $this->getRepository();
    $viewer = $this->getViewer();

    $repository_phid = $repository->getPHID();

    $device = AlmanacKeys::getLiveDevice();
    $device_phid = $device->getPHID();

    $table = new PhabricatorRepositoryWorkingCopyVersion();
    $locked_connection = $table->establishConnection('w');

    $write_lock = PhabricatorRepositoryWorkingCopyVersion::getWriteLock(
      $repository_phid);

    $write_lock->useSpecificConnection($locked_connection);

    $lock_wait = phutil_units('2 minutes in seconds');

    $this->logLine(
      pht(
        'Waiting up to %s second(s) for a cluster write lock...',
        new PhutilNumber($lock_wait)));

    try {
      $start = PhabricatorTime::getNow();
      $write_lock->lock($lock_wait);
      $waited = (PhabricatorTime::getNow() - $start);

      if ($waited) {
        $this->logLine(
          pht(
            'Acquired write lock after %s second(s).',
            new PhutilNumber($waited)));
      } else {
        $this->logLine(
          pht(
            'Acquired write lock immediately.'));
      }
    } catch (Exception $ex) {
      throw new PhutilProxyException(
        pht(
          'Failed to acquire write lock after waiting %s second(s). You '.
          'may be able to retry later.',
          new PhutilNumber($lock_wait)));
    }

    $versions = PhabricatorRepositoryWorkingCopyVersion::loadVersions(
      $repository_phid);
    foreach ($versions as $version) {
      if (!$version->getIsWriting()) {
        continue;
      }

      throw new Exception(
        pht(
          'An previous write to this repository was interrupted; refusing '.
          'new writes. This issue requires operator intervention to resolve, '.
          'see "Write Interruptions" in the "Cluster: Repositories" in the '.
          'documentation for instructions.'));
    }

    try {
      $max_version = $this->synchronizeWorkingCopyBeforeRead();
    } catch (Exception $ex) {
      $write_lock->unlock();
      throw $ex;
    }

    $pid = getmypid();
    $hash = Filesystem::readRandomCharacters(12);
    $this->clusterWriteOwner = "{$pid}.{$hash}";

    PhabricatorRepositoryWorkingCopyVersion::willWrite(
      $locked_connection,
      $repository_phid,
      $device_phid,
      array(
        'userPHID' => $viewer->getPHID(),
        'epoch' => PhabricatorTime::getNow(),
        'devicePHID' => $device_phid,
      ),
      $this->clusterWriteOwner);

    $this->clusterWriteVersion = $max_version;
    $this->clusterWriteLock = $write_lock;
  }


  /**
   * @task sync
   */
  public function synchronizeWorkingCopyAfterWrite() {
    if (!$this->shouldEnableSynchronization()) {
      return;
    }

    if (!$this->clusterWriteLock) {
      throw new Exception(
        pht(
          'Trying to synchronize after write, but not holding a write '.
          'lock!'));
    }

    $repository = $this->getRepository();
    $repository_phid = $repository->getPHID();

    $device = AlmanacKeys::getLiveDevice();
    $device_phid = $device->getPHID();

    // It is possible that we've lost the global lock while receiving the push.
    // For example, the master database may have been restarted between the
    // time we acquired the global lock and now, when the push has finished.

    // We wrote a durable lock while we were holding the the global lock,
    // essentially upgrading our lock. We can still safely release this upgraded
    // lock even if we're no longer holding the global lock.

    // If we fail to release the lock, the repository will be frozen until
    // an operator can figure out what happened, so we try pretty hard to
    // reconnect to the database and release the lock.

    $now = PhabricatorTime::getNow();
    $duration = phutil_units('5 minutes in seconds');
    $try_until = $now + $duration;

    $did_release = false;
    $already_failed = false;
    while (PhabricatorTime::getNow() <= $try_until) {
      try {
        // NOTE: This means we're still bumping the version when pushes fail. We
        // could select only un-rejected events instead to bump a little less
        // often.

        $new_log = id(new PhabricatorRepositoryPushEventQuery())
          ->setViewer(PhabricatorUser::getOmnipotentUser())
          ->withRepositoryPHIDs(array($repository_phid))
          ->setLimit(1)
          ->executeOne();

        $old_version = $this->clusterWriteVersion;
        if ($new_log) {
          $new_version = $new_log->getID();
        } else {
          $new_version = $old_version;
        }

        PhabricatorRepositoryWorkingCopyVersion::didWrite(
          $repository_phid,
          $device_phid,
          $this->clusterWriteVersion,
          $new_log->getID(),
          $this->clusterWriteOwner);
        $did_release = true;
        break;
      } catch (AphrontConnectionQueryException $ex) {
        $connection_exception = $ex;
      } catch (AphrontConnectionLostQueryException $ex) {
        $connection_exception = $ex;
      }

      if (!$already_failed) {
        $already_failed = true;
        $this->logLine(
          pht('CRITICAL. Failed to release cluster write lock!'));

        $this->logLine(
          pht(
            'The connection to the master database was lost while receiving '.
            'the write.'));

        $this->logLine(
          pht(
            'This process will spend %s more second(s) attempting to '.
            'recover, then give up.',
            new PhutilNumber($duration)));
      }

      sleep(1);
    }

    if ($did_release) {
      if ($already_failed) {
        $this->logLine(
          pht('RECOVERED. Link to master database was restored.'));
      }
      $this->logLine(pht('Released cluster write lock.'));
    } else {
      throw new Exception(
        pht(
          'Failed to reconnect to master database and release held write '.
          'lock ("%s") on device "%s" for repository "%s" after trying '.
          'for %s seconds(s). This repository will be frozen.',
          $this->clusterWriteOwner,
          $device->getName(),
          $this->getDisplayName(),
          new PhutilNumber($duration)));
    }

    // We can continue even if we've lost this lock, everything is still
    // consistent.
    try {
      $this->clusterWriteLock->unlock();
    } catch (Exception $ex) {
      // Ignore.
    }

    $this->clusterWriteLock = null;
    $this->clusterWriteOwner = null;
  }


/* -(  Internals  )---------------------------------------------------------- */


  /**
   * @task internal
   */
  private function shouldEnableSynchronization() {
    $repository = $this->getRepository();

    $service_phid = $repository->getAlmanacServicePHID();
    if (!$service_phid) {
      return false;
    }

    // TODO: For now, this is only supported for Git.
    if (!$repository->isGit()) {
      return false;
    }

    // TODO: It may eventually make sense to try to version and synchronize
    // observed repositories (so that daemons don't do reads against out-of
    // date hosts), but don't bother for now.
    if (!$repository->isHosted()) {
      return false;
    }

    $device = AlmanacKeys::getLiveDevice();
    if (!$device) {
      return false;
    }

    return true;
  }


  /**
   * @task internal
   */
  private function synchronizeWorkingCopyFromDevices(array $device_phids) {
    $repository = $this->getRepository();

    $service = $repository->loadAlmanacService();
    if (!$service) {
      throw new Exception(pht('Failed to load repository cluster service.'));
    }

    $device_map = array_fuse($device_phids);
    $bindings = $service->getActiveBindings();

    $fetchable = array();
    foreach ($bindings as $binding) {
      // We can't fetch from nodes which don't have the newest version.
      $device_phid = $binding->getDevicePHID();
      if (empty($device_map[$device_phid])) {
        continue;
      }

      // TODO: For now, only fetch over SSH. We could support fetching over
      // HTTP eventually.
      if ($binding->getAlmanacPropertyValue('protocol') != 'ssh') {
        continue;
      }

      $fetchable[] = $binding;
    }

    if (!$fetchable) {
      throw new Exception(
        pht(
          'Leader lost: no up-to-date nodes in repository cluster are '.
          'fetchable.'));
    }

    $caught = null;
    foreach ($fetchable as $binding) {
      try {
        $this->synchronizeWorkingCopyFromBinding($binding);
        $caught = null;
        break;
      } catch (Exception $ex) {
        $caught = $ex;
      }
    }

    if ($caught) {
      throw $caught;
    }
  }


  /**
   * @task internal
   */
  private function synchronizeWorkingCopyFromBinding($binding) {
    $repository = $this->getRepository();
    $device = AlmanacKeys::getLiveDevice();

    $this->logLine(
      pht(
        'Synchronizing this device ("%s") from cluster leader ("%s") before '.
        'read.',
        $device->getName(),
        $binding->getDevice()->getName()));

    $fetch_uri = $repository->getClusterRepositoryURIFromBinding($binding);
    $local_path = $repository->getLocalPath();

    if ($repository->isGit()) {
      if (!Filesystem::pathExists($local_path)) {
        throw new Exception(
          pht(
            'Repository "%s" does not have a working copy on this device '.
            'yet, so it can not be synchronized. Wait for the daemons to '.
            'construct one or run `bin/repository update %s` on this host '.
            '("%s") to build it explicitly.',
            $repository->getDisplayName(),
            $repository->getMonogram(),
            $device->getName()));
      }

      $argv = array(
        'fetch --prune -- %s %s',
        $fetch_uri,
        '+refs/*:refs/*',
      );
    } else {
      throw new Exception(pht('Binding sync only supported for git!'));
    }

    $future = DiffusionCommandEngine::newCommandEngine($repository)
      ->setArgv($argv)
      ->setConnectAsDevice(true)
      ->setSudoAsDaemon(true)
      ->setProtocol($fetch_uri->getProtocol())
      ->newFuture();

    $future->setCWD($local_path);

    try {
      $future->resolvex();
    } catch (Exception $ex) {
      $this->logLine(
        pht(
          'Synchronization of "%s" from leader "%s" failed: %s',
          $device->getName(),
          $binding->getDevice()->getName(),
          $ex->getMessage()));
      throw $ex;
    }
  }


  /**
   * @task internal
   */
  private function logLine($message) {
    return $this->logText("# {$message}\n");
  }


  /**
   * @task internal
   */
  private function logText($message) {
    $log = $this->logger;
    if ($log) {
      $log->writeClusterEngineLogMessage($message);
    }
    return $this;
  }
}
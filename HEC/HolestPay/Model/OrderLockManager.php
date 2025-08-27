<?php
/**
 * HolestPay Order Lock Manager
 * 
 * Implements database-based locking mechanism to prevent concurrent processing
 * of the same order from multiple requests (webhooks, forwarded responses, etc.)
 * 
 * This is a direct port of the WooCommerce locking mechanism from hpay_class.php:
 * - Uses database INSERT with primary key constraint for atomic locking
 * - Implements retry logic with exponential backoff
 * - Automatic cleanup of expired locks
 * - Prevents race conditions in payment processing
 * 
 * Lock behavior:
 * - Immediate lock attempt
 * - If failed, wait 1 second and retry (up to 16 attempts)
 * - Lock expires after 16 seconds
 * - Automatic cleanup of locks older than 30 seconds
 * 
 * @see wooCommerce-example/holestpay/hpay_class.php::lockHOrderUpdate()
 */
namespace HEC\HolestPay\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class OrderLockManager
{
    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    protected $lockTableName;

    /**
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
        $this->lockTableName = $this->resourceConnection->getTableName('holestpay_order_locks');
    }

    /**
     * Try to acquire a lock for an order
     *
     * @param string $orderUid
     * @return bool
     */
    public function lockOrder($orderUid)
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $timestamp = time();

            // First, try to insert the lock
            $locked = $this->tryInsertLock($connection, $orderUid, $timestamp);
            if ($locked) {
                $this->logger->warning('HolestPay: Order lock acquired', ['order_uid' => $orderUid]);
                return true;
            }

            // Check if existing lock is expired (older than 16 seconds)
            $existingLock = $this->getExistingLock($connection, $orderUid);
            if ($existingLock && ($existingLock + 16) < $timestamp) {
                $this->logger->warning('HolestPay: Existing lock expired, acquiring', ['order_uid' => $orderUid]);
                return true;
            }

            // Wait and retry up to 16 times with 1-second intervals
            for ($i = 0; $i < 16; $i++) {
                sleep(1);
                $timestamp = time();

                $locked = $this->tryInsertLock($connection, $orderUid, $timestamp);
                if ($locked) {
                    $this->logger->warning('HolestPay: Order lock acquired after retry', [
                        'order_uid' => $orderUid,
                        'attempts' => $i + 1
                    ]);
                    return true;
                }

                // Clean up expired locks (older than 30 seconds)
                $this->cleanupExpiredLocks($connection, $timestamp - 30);
            }

            $this->logger->warning('HolestPay: Failed to acquire order lock after all retries', ['order_uid' => $orderUid]);
            return false;

        } catch (\Exception $e) {
            $this->logger->error('HolestPay: Error acquiring order lock: ' . $e->getMessage(), [
                'order_uid' => $orderUid,
                'exception' => $e
            ]);
            return false;
        }
    }

    /**
     * Release the lock for an order
     *
     * @param string $orderUid
     * @return bool
     */
    public function unlockOrder($orderUid)
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            
            $result = $connection->delete(
                $this->lockTableName,
                ['order_uid = ?' => $orderUid]
            );

            if ($result) {
                $this->logger->warning('HolestPay: Order lock released', ['order_uid' => $orderUid]);
                return true;
            }

            $this->logger->warning('HolestPay: No lock found to release', ['order_uid' => $orderUid]);
            return false;

        } catch (\Exception $e) {
            $this->logger->error('HolestPay: Error releasing order lock: ' . $e->getMessage(), [
                'order_uid' => $orderUid,
                'exception' => $e
            ]);
            return false;
        }
    }

    /**
     * Try to insert a lock record
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $orderUid
     * @param int $timestamp
     * @return bool
     */
    protected function tryInsertLock($connection, $orderUid, $timestamp)
    {
        try {
            $data = [
                'order_uid' => $orderUid,
                'lock_timestamp' => $timestamp,
                'created_at' => date('Y-m-d H:i:s', $timestamp)
            ];

            $connection->insert($this->lockTableName, $data);
            return true;

        } catch (\Exception $e) {
            // Lock already exists or other error
            return false;
        }
    }

    /**
     * Get existing lock timestamp
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $orderUid
     * @return int|null
     */
    protected function getExistingLock($connection, $orderUid)
    {
        try {
            $select = $connection->select()
                ->from($this->lockTableName, ['lock_timestamp'])
                ->where('order_uid = ?', $orderUid);

            $result = $connection->fetchOne($select);
            return $result ? (int)$result : null;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Clean up expired locks
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param int $expiredTimestamp
     */
    protected function cleanupExpiredLocks($connection, $expiredTimestamp)
    {
        try {
            $connection->delete(
                $this->lockTableName,
                ['lock_timestamp < ?' => $expiredTimestamp]
            );
        } catch (\Exception $e) {
            // Log but don't fail the main operation
            $this->logger->warning('HolestPay: Error cleaning up expired locks: ' . $e->getMessage());
        }
    }

    /**
     * Check if an order is currently locked
     *
     * @param string $orderUid
     * @return bool
     */
    public function isOrderLocked($orderUid)
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $existingLock = $this->getExistingLock($connection, $orderUid);
            
            if (!$existingLock) {
                return false;
            }

            // Check if lock is expired (older than 30 seconds)
            return (time() - $existingLock) < 30;

        } catch (\Exception $e) {
            $this->logger->error('HolestPay: Error checking order lock status: ' . $e->getMessage(), [
                'order_uid' => $orderUid,
                'exception' => $e
            ]);
            return false;
        }
    }
}

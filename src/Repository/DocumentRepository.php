<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentKind;
use App\Entity\DocumentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
final class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    public function save(Document $document): void
    {
        $this->getEntityManager()->persist($document);
        $this->getEntityManager()->flush();
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    public function findByStatusId(string $statusId): ?Document
    {
        return $this->findOneBy(['statusId' => $statusId]);
    }

    public function findByDownloadId(string $downloadId): ?Document
    {
        return $this->findOneBy(['downloadId' => $downloadId]);
    }

    /**
     * @return list<Document>
     */
    public function listForOwner(string $owner, int $page, int $perPage): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * Counts one download against the document's limit, atomically across processes.
     *
     * @return bool false when the limit has been reached
     */
    public function countDownload(Document $document): bool
    {
        $updated = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE document SET download_count = download_count + 1
             WHERE status_id = :id AND (download_limit IS NULL OR download_count < download_limit)',
            ['id' => $document->statusId],
        );

        return 1 === $updated;
    }

    /**
     * @return list<Document> stored documents whose expiry has passed
     */
    public function findExpired(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.status = :completed')
            ->andWhere('d.kind != :sync')
            ->andWhere('d.expiresAt IS NOT NULL AND d.expiresAt <= :now')
            ->setParameter('completed', DocumentStatus::Completed)
            ->setParameter('sync', DocumentKind::Sync)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Deletes log entries older than $before: synchronous documents, failed and expired ones.
     */
    public function deleteLogsBefore(\DateTimeImmutable $before): int
    {
        $deleted = $this->createQueryBuilder('d')
            ->delete()
            ->where('d.createdAt < :before')
            ->andWhere('d.kind = :sync OR d.status IN (:finished)')
            ->setParameter('before', $before)
            ->setParameter('sync', DocumentKind::Sync)
            ->setParameter('finished', [DocumentStatus::Failed, DocumentStatus::Expired])
            ->getQuery()
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Setting> */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    /** @return array<string, Setting> indexés par nom */
    public function allIndexed(): array
    {
        $settings = [];
        foreach ($this->findAll() as $setting) {
            $settings[$setting->getName()] = $setting;
        }

        return $settings;
    }
}

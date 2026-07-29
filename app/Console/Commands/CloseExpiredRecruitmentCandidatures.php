<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RecruitmentService;
use Illuminate\Console\Command;

/** Clôture et note les candidatures de recrutement dont le délai est écoulé. */
class CloseExpiredRecruitmentCandidatures extends Command
{
    protected $signature = 'recruitment:close-expired';

    protected $description = 'Clôture et note les candidatures de recrutement dont le temps est écoulé.';

    public function __construct(private readonly RecruitmentService $recruitment)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = $this->recruitment->closeExpired();
        $this->info("{$count} candidature(s) clôturée(s) pour expiration.");

        return self::SUCCESS;
    }
}

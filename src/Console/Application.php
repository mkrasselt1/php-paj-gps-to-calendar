<?php

namespace PajGpsCalendar\Console;

use Symfony\Component\Console\Application as BaseApplication;
use PajGpsCalendar\Console\Commands\CheckCommand;
use PajGpsCalendar\Console\Commands\HistoryCommand;
use PajGpsCalendar\Console\Commands\SetupCommand;
use PajGpsCalendar\Console\Commands\TestPajCommand;
use PajGpsCalendar\Console\Commands\TestCrmCommand;
use PajGpsCalendar\Console\Commands\TestCalendarCommand;
use PajGpsCalendar\Console\Commands\AnalyzeCrmCommand;
use PajGpsCalendar\Console\Commands\ConfigWizardCommand;
use PajGpsCalendar\Console\Commands\SyncCrmToPajCommand;
use PajGpsCalendar\Console\Commands\MonitorVisitsCommand;

class Application extends BaseApplication
{
    public function __construct()
    {
        parent::__construct('PAJ GPS to Calendar', '1.0.0');
        
        // Hauptkommandos
        $this->add(new CheckCommand());
        $this->add(new HistoryCommand());
        $this->add(new SetupCommand());
        
        // Test- und Diagnose-Kommandos
        $this->add(new TestPajCommand());
        $this->add(new TestCrmCommand());
        $this->add(new TestCalendarCommand());
        $this->add(new AnalyzeCrmCommand());
        
        // Synchronisation und Monitoring
        $this->add(new SyncCrmToPajCommand());
        $this->add(new MonitorVisitsCommand());
        
        // Konfigurationsassistent
        $this->add(new ConfigWizardCommand());
    }
}

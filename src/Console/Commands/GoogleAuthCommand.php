<?php

namespace PajGpsCalendar\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use PajGpsCalendar\Services\ConfigService;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class GoogleAuthCommand extends Command
{
    protected static $defaultName = 'google-auth';

    protected function configure(): void
    {
        $this
            ->setDescription('Authentifiziert die Anwendung für Google Calendar API')
            ->addOption(
                'reset',
                'r',
                InputOption::VALUE_NONE,
                'Löscht vorhandene Tokens und startet Authentifizierung neu'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln('<info>Google Calendar Authentifizierung</info>');
            $output->writeln('==================================');
            $output->writeln('');

            // Config laden
            $config = new ConfigService();
            $logger = new Logger('google-auth');
            $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

            // Prüfen ob Google Calendar aktiviert ist
            if (!$config->get('calendar.google_calendar.enabled', false)) {
                $output->writeln('<error>Google Calendar ist nicht aktiviert in der Konfiguration!</error>');
                $output->writeln('Setzen Sie calendar.google_calendar.enabled auf true in config/config.yaml');
                return Command::FAILURE;
            }

            $credentialsPath = $config->get('calendar.google_calendar.credentials_path');
            $tokenPath = dirname($credentialsPath) . '/google-token.json';

            // Credentials-Datei prüfen
            if (!file_exists($credentialsPath)) {
                $output->writeln("<error>Google Credentials-Datei nicht gefunden: {$credentialsPath}</error>");
                $output->writeln('Bitte laden Sie die credentials.json von Google Cloud Console herunter.');
                return Command::FAILURE;
            }

            // Vorhandene Tokens löschen falls --reset angegeben
            if ($input->getOption('reset') && file_exists($tokenPath)) {
                unlink($tokenPath);
                $output->writeln('<info>Vorhandene Tokens wurden gelöscht.</info>');
            }

            // Prüfen ob bereits authentifiziert
            if (file_exists($tokenPath)) {
                $output->writeln('<info>Google Calendar ist bereits authentifiziert.</info>');
                $output->writeln("Token-Datei: {$tokenPath}");

                // Testen ob Token gültig ist
                $googleClient = new GoogleClient();
                $googleClient->setAuthConfig($credentialsPath);
                $accessToken = json_decode(file_get_contents($tokenPath), true);
                $googleClient->setAccessToken($accessToken);

                if ($googleClient->isAccessTokenExpired()) {
                    $output->writeln('<comment>Access Token ist abgelaufen, versuche Refresh...</comment>');
                    try {
                        $googleClient->fetchAccessTokenWithRefreshToken($googleClient->getRefreshToken());
                        file_put_contents($tokenPath, json_encode($googleClient->getAccessToken()));
                        $output->writeln('<info>Token erfolgreich erneuert!</info>');
                    } catch (\Exception $e) {
                        $output->writeln('<error>Token konnte nicht erneuert werden:</error>');
                        $output->writeln($e->getMessage());
                        unlink($tokenPath);
                        $output->writeln('<info>Token-Datei wurde gelöscht. Starten Sie die Authentifizierung neu.</info>');
                        return Command::FAILURE;
                    }
                } else {
                    $output->writeln('<info>Access Token ist gültig.</info>');
                }

                return Command::SUCCESS;
            }

            // OAuth-Flow starten
            $output->writeln('<info>Starte Google OAuth Authentifizierung...</info>');
            $output->writeln('');

            $googleClient = new GoogleClient();
            $googleClient->setApplicationName($config->get('calendar.google_calendar.application_name', 'PAJ GPS to Calendar'));
            $googleClient->setScopes([GoogleCalendar::CALENDAR_EVENTS]);
            $googleClient->setAuthConfig($credentialsPath);
            $googleClient->setAccessType('offline');
            $googleClient->setPrompt('select_account consent');
            $googleClient->setRedirectUri('urn:ietf:wg:oauth:2.0:oob'); // Für Desktop-Apps

            // Authentifizierungs-URL generieren
            $authUrl = $googleClient->createAuthUrl();

            $output->writeln('<comment>Bitte öffnen Sie diese URL in Ihrem Browser:</comment>');
            $output->writeln('');
            $output->writeln("<info>{$authUrl}</info>");
            $output->writeln('');
            $output->writeln('<comment>Anleitung:</comment>');
            $output->writeln('1. Klicken Sie auf den Link oben');
            $output->writeln('2. Wählen Sie Ihr Google-Konto aus');
            $output->writeln('3. Erlauben Sie den Zugriff auf Google Calendar');
            $output->writeln('4. Sie werden zu einer Seite mit einem Authorization Code weitergeleitet');
            $output->writeln('5. Kopieren Sie den Code und fügen Sie ihn hier ein');
            $output->writeln('');

            // Authorization Code einlesen
            $output->write('<question>Authorization Code: </question>');
            $authCode = trim(fgets(STDIN));

            if (empty($authCode)) {
                $output->writeln('<error>Kein Authorization Code eingegeben!</error>');
                return Command::FAILURE;
            }

            $output->writeln('<info>Tausche Authorization Code gegen Access Token...</info>');

            // Token mit dem Code austauschen
            $accessToken = $googleClient->fetchAccessTokenWithAuthCode($authCode);

            if (isset($accessToken['error'])) {
                $output->writeln('<error>Fehler beim Austausch des Authorization Codes:</error>');
                $output->writeln($accessToken['error']);
                if (isset($accessToken['error_description'])) {
                    $output->writeln($accessToken['error_description']);
                }
                return Command::FAILURE;
            }

            // Token speichern
            file_put_contents($tokenPath, json_encode($accessToken));
            $output->writeln('<info>✅ Google OAuth erfolgreich abgeschlossen!</info>');
            $output->writeln("Token gespeichert in: {$tokenPath}");

            // Test-Call machen um zu prüfen ob alles funktioniert
            $output->writeln('<info>Teste Google Calendar API...</info>');
            $calendarService = new GoogleCalendar($googleClient);

            try {
                $calendarId = $config->get('calendar.google_calendar.calendar_id', 'primary');
                $calendar = $calendarService->calendars->get($calendarId);
                $output->writeln("<info>✅ Verbindung zu Google Calendar erfolgreich!</info>");
                $output->writeln("Kalender: {$calendar->getSummary()}");
            } catch (\Exception $e) {
                $output->writeln('<error>Fehler beim Testen der Google Calendar API:</error>');
                $output->writeln($e->getMessage());
                return Command::FAILURE;
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>Fehler: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}

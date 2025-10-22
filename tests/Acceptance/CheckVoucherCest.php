<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;
use PDO;
use Exception;
use Symfony\Component\Dotenv\Dotenv;

final class CheckVoucherCest
{
    private PDO $pdo;
    private int $batchSize = 100;

    public function _before(AcceptanceTester $I): void
    {
        $dotenv = new Dotenv();
        $dotenv->load('.env');
        
        // Setup DB connection once before tests (adjust credentials if perlu)
        $host = $_ENV['DB_HOST'];
        $db   = $_ENV['DB_NAME'];
        $user = $_ENV['DB_USER'];
        $pass = $_ENV['DB_PASSWORD'];
        $dsn  = "pgsql:host={$host};dbname={$db}";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (Exception $e) {
            // Jika koneksi DB gagal, hentikan supaya kamu tahu
            throw new \RuntimeException('Gagal konek DB: ' . $e->getMessage());
        }
    }

    public function loginUserManager(AcceptanceTester $I)
    {
        $timeLimits = [
            'K50' => 2592000,
            'K10' => 604800,
            'K5' => 259200,
            'K2' => 86400,
            'VIP' => 86400,
        ];
        while (true) {
            // Ambil batch user yang belum dicek
            $sql = <<<SQL
                SELECT 
                    t1.username,
                    t1.value as password,
                    t2.groupname
                FROM radcheck t1
                LEFT JOIN radusergroup t2 ON t1.username = t2.username
                WHERE t1.attribute=:attr AND t1. comment IS NULL 
                -- AND t2.groupname NOT IN ('staff', 'staff2', 'manager', 'VIS')
                LIMIT :limit
SQL;
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':attr', 'Cleartext-Password', PDO::PARAM_STR);
            $stmt->bindValue(':limit', $this->batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                $I->comment('Tidak ada akun tersisa untuk dicek.');
                sleep(300);
                //break;
            }

            foreach ($rows as $row) {
                $username = $row['username'];
                $password = $row['password'];

                $I->comment("Memproses akun: {$username}");

                try {
                    // Pastikan kita berada di halaman login (fresh)
                    // restartBrowser bila perlu (tersedia pada WebDriver module jika restart true)
                    // $I->restartBrowser(); // uncomment jika kamu punya method ini / modul mendukung

                    // Buka halaman login UM
                    $I->amOnPage('/um/');

                    // Tunggu field login muncul (nama field di UM: "username")
                    $I->waitForElementVisible('input[name="username"]', 10);
                    $I->fillField('input[name="username"]', $username);

                    // Password
                    $I->fillField('input[name="password"]', $password);

                    $I->click('input[type=submit]');

                    // Setelah login, buka halaman status user
                    //$I->amOnPage('/um/user/#status');

                    // Tunggu elemen #status-profiles muncul
                    $I->waitForElementVisible('#status-profiles', 10);

                    // Ambil teks dari elemen tersebut
                    $text = $I->grabTextFrom('#status-profiles');
                    $text2 = $I->grabTextFrom('#status-exp-profiles');
                    $I->comment("#status-profiles: " . trim(preg_replace('/\s+/', ' ', $text)));
                    $I->comment("#status-exp-profiles: " . trim(preg_replace('/\s+/', ' ', $text2)));

                    if (stripos($text, 'Waiting') !== false) {
                        $I->comment("Found 'Waiting'");
                        $this->markChecked($I, $username, 'Waiting');
                    } else if (stripos($text, 'Running active') !== false) {
                        $I->comment("Found 'Running active'");
                        $this->ajax($I, $username, 'Running active');
                    } else if (stripos($text, 'Running') !== false) {
                        $I->comment("Found 'Running'");
                        $this->ajax($I, $username, 'Running');
                    } else if (stripos($text2, 'Used') !== false) {
                        $I->comment("Found 'Used'");
                        $this->ajax($I, $username, 'Used');
                    }

                    // Update DB: isi comment = 'checked' (kamu minta semua yang diproses diisi checked)

                    // logout/bersihkan session sebelum akun berikutnya
                    // Coba klik logout bila ada; jika tidak, reload halaman login (memaksa state fresh)
                    try {
                        // klik logout kalau tersedia
                        $I->click('Logout');
                    } catch (Exception $e) {
                        // fallback: kembali ke halaman login untuk memaksa login ulang
                        $I->amOnPage('/um/');
                    }

                    // beri jeda kecil agar tidak spam server
                    usleep(200000);
                } catch (Exception $e) {
                    $I->comment("Terjadi error saat memproses {$username}: " . $e->getMessage());
                    // tetap mark checked supaya tidak terulang — sesuaikan jika tidak mau demikian
                    //$this->markChecked($I, $username);
                    // lanjut ke akun berikutnya
                    continue;
                }
            }
        }

        $I->comment('Selesai batch.');
    }

    private function markChecked(AcceptanceTester $I, string $username, string $comment): void
    {
        try {
            $u = $this->pdo->prepare("UPDATE radcheck SET comment = :c WHERE username = :u AND attribute = :a");
            $u->execute([':c' => $comment, ':u' => $username, ':a' => 'Cleartext-Password']);
            $I->comment('update db');
        } catch (Exception $e) {
            // log tapi jangan throw agar loop tetap jalan
            // jika ingin, bisa menulis ke file log
        }
    }

    private function ajax(AcceptanceTester $I, string $username, $comment)
    {
        $I->amOnPage('/um/api/getUserSessions?time=' . microtime());
        $I->waitForElementVisible('pre', 10);
        $jsonText = $I->grabTextFrom('pre'); // misal muncul di <pre>
        $data = json_decode($jsonText, true);
        if ($data['success']) {
            $sql = <<<SQL
                INSERT INTO radacct (
                    acctsessionid,
                    acctuniqueid,
                    nasipaddress,
                    username,
                    acctstarttime,
                    acctupdatetime,
                    acctstoptime,
                    acctsessiontime,
                    acctinputoctets,
                    acctoutputoctets,
                    acctterminatecause
                ) VALUES (
                    md5(random()::text || clock_timestamp()::text), -- acctsessionid
                    md5(random()::text || now()::text),             -- acctuniqueid
                    '10.2.1.1',
                    :username,
                    :startTime,
                    :endTIme,
                    :endTIme,
                    :uptime,
                    :download,
                    :upload,
                    'Lost-Service'
                );
SQL;
            try {
                $this->pdo->beginTransaction();
                foreach ($data['data']['sessions'] as $row) {
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->bindValue(':username', $username, PDO::PARAM_STR);
                    $stmt->bindValue(':startTime', $row['startTime'] . '+10', PDO::PARAM_STR);
                    if ($row['endTime'] == 'still-active') {
                        $stmt->bindValue(':endTIme', $row['startTime'] . '+10', PDO::PARAM_STR);
                    } else {
                        $stmt->bindValue(':endTIme', $row['endTime'] . '+10', PDO::PARAM_STR);
                    }
                    $stmt->bindValue(':uptime', $I->stringToSeconds($row['uptime']), PDO::PARAM_INT);
                    $stmt->bindValue(':download', $I->convertSizeToBytes($row['downloaded']), PDO::PARAM_INT);
                    $stmt->bindValue(':upload', $I->convertSizeToBytes($row['uploaded']), PDO::PARAM_INT);
                    $stmt->execute();
                }
                $this->markChecked($I, $username, $comment);
                $this->pdo->commit();
            } catch (Exception $e) {
                $this->pdo->rollBack();  // Jika error, rollback
                echo "Gagal: " . $e->getMessage();
            }
        } else {
            $I->comment('tidak ada data');
        }
        $I->amOnPage('/um/');
    }
}

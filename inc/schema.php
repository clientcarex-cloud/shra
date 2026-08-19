<?php
/** Table definitions. All CREATE ... IF NOT EXISTS so install.php is re-runnable. */
function schema_statements(): array
{
    return [
'settings' => "CREATE TABLE IF NOT EXISTS settings (
  skey   VARCHAR(64) NOT NULL PRIMARY KEY,
  svalue TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'users' => "CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  username      VARCHAR(60)  NULL UNIQUE,
  email         VARCHAR(150) NULL,
  phone         VARCHAR(20)  NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','manager','staff','trainer') NOT NULL DEFAULT 'staff',
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login    DATETIME NULL,
  created_at    DATETIME NOT NULL,
  INDEX (email), INDEX (phone), INDEX (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'trainers' => "CREATE TABLE IF NOT EXISTS trainers (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NULL,
  name           VARCHAR(120) NOT NULL,
  phone          VARCHAR(20)  NULL,
  email          VARCHAR(150) NULL,
  specialization VARCHAR(150) NULL,
  experience_yrs DECIMAL(4,1) NOT NULL DEFAULT 0,
  joining_date   DATE NULL,
  session_rate   DECIMAL(10,2) NOT NULL DEFAULT 0,
  address        VARCHAR(255) NULL,
  notes          TEXT NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at     DATETIME NOT NULL,
  INDEX (status), INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'customers' => "CREATE TABLE IF NOT EXISTS customers (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(20) NOT NULL UNIQUE,
  first_name     VARCHAR(80) NOT NULL,
  last_name      VARCHAR(80) NULL,
  father_spouse  VARCHAR(120) NULL,
  guardian_name  VARCHAR(120) NULL,
  guardian_rel   VARCHAR(60)  NULL,
  dob            DATE NULL,
  place_of_birth VARCHAR(120) NULL,
  gender         ENUM('male','female','other') NULL,
  riding_level   ENUM('beginner','novice','intermediate','advanced') NOT NULL DEFAULT 'beginner',
  marital_status ENUM('single','married','divorced','other') NULL,
  phone          VARCHAR(20) NOT NULL,
  alt_phone      VARCHAR(20) NULL,
  email          VARCHAR(150) NULL,
  address        VARCHAR(255) NULL,
  city           VARCHAR(80)  NULL,
  postcode       VARCHAR(12)  NULL,
  country        VARCHAR(80)  NULL DEFAULT 'India',
  nationality    VARCHAR(80)  NULL DEFAULT 'Indian',
  category       ENUM('child','adult') NOT NULL DEFAULT 'adult',
  medical_notes  TEXT NULL,
  notes          TEXT NULL,
  portal_pin     VARCHAR(8) NULL,
  source         VARCHAR(60) NULL,
  lead_id        INT NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_by     INT NULL,
  created_at     DATETIME NOT NULL,
  INDEX (phone), INDEX (status), INDEX (category), INDEX (first_name), INDEX (last_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'plans' => "CREATE TABLE IF NOT EXISTS plans (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(120) NOT NULL,
  audience     ENUM('child','adult','all') NOT NULL DEFAULT 'all',
  kind         ENUM('guest','package') NOT NULL DEFAULT 'package',
  sessions     INT NOT NULL DEFAULT 1,
  duration_min INT NOT NULL DEFAULT 30,
  original_amt DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount_pct DECIMAL(5,2)  NOT NULL DEFAULT 0,
  amount       DECIMAL(10,2) NOT NULL DEFAULT 0,
  validity_days INT NOT NULL DEFAULT 30,
  sort_order   INT NOT NULL DEFAULT 0,
  status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at   DATETIME NOT NULL,
  INDEX (status), INDEX (audience), INDEX (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'subscriptions' => "CREATE TABLE IF NOT EXISTS subscriptions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  customer_id    INT NOT NULL,
  plan_id        INT NULL,
  plan_name      VARCHAR(120) NOT NULL,
  trainer_id     INT NULL,
  start_date     DATE NOT NULL,
  end_date       DATE NULL,
  total_sessions INT NOT NULL DEFAULT 0,
  used_sessions  INT NOT NULL DEFAULT 0,
  duration_min   INT NOT NULL DEFAULT 30,
  price          DECIMAL(10,2) NOT NULL DEFAULT 0,
  status         ENUM('active','completed','expired','cancelled') NOT NULL DEFAULT 'active',
  notes          TEXT NULL,
  created_by     INT NULL,
  created_at     DATETIME NOT NULL,
  INDEX (customer_id), INDEX (status), INDEX (trainer_id), INDEX (end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'ride_sessions' => "CREATE TABLE IF NOT EXISTS ride_sessions (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  customer_id     INT NOT NULL,
  subscription_id INT NULL,
  trainer_id      INT NULL,
  ride_type       ENUM('subscription','guest') NOT NULL DEFAULT 'subscription',
  horse_name      VARCHAR(80) NULL,
  ride_date       DATE NOT NULL,
  ride_time       TIME NULL,
  duration_min    INT NOT NULL DEFAULT 30,
  status          ENUM('scheduled','present','no_show','cancelled') NOT NULL DEFAULT 'present',
  skills          VARCHAR(255) NULL,
  remarks         TEXT NULL,
  marked_by       INT NULL,
  created_at      DATETIME NOT NULL,
  INDEX (customer_id), INDEX (subscription_id), INDEX (ride_date), INDEX (trainer_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'invoices' => "CREATE TABLE IF NOT EXISTS invoices (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no      VARCHAR(40) NOT NULL UNIQUE,
  seq_no          INT NOT NULL DEFAULT 0,
  fy              VARCHAR(9)  NOT NULL,
  token           VARCHAR(24) NOT NULL UNIQUE,
  customer_id     INT NOT NULL,
  subscription_id INT NULL,
  source          ENUM('staff','self') NOT NULL DEFAULT 'staff',
  issue_date      DATE NOT NULL,
  due_date        DATE NULL,
  subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount        DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax_pct         DECIMAL(5,2)  NOT NULL DEFAULT 0,
  tax_amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
  total           DECIMAL(10,2) NOT NULL DEFAULT 0,
  paid_amount     DECIMAL(10,2) NOT NULL DEFAULT 0,
  status          ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  notes           TEXT NULL,
  created_by      INT NULL,
  created_at      DATETIME NOT NULL,
  INDEX (customer_id), INDEX (status), INDEX (issue_date), INDEX (fy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'invoice_items' => "CREATE TABLE IF NOT EXISTS invoice_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id  INT NOT NULL,
  plan_id     INT NULL,
  description VARCHAR(255) NOT NULL,
  qty         DECIMAL(8,2)  NOT NULL DEFAULT 1,
  rate        DECIMAL(10,2) NOT NULL DEFAULT 0,
  amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
  INDEX (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'payments' => "CREATE TABLE IF NOT EXISTS payments (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id  INT NOT NULL,
  amount      DECIMAL(10,2) NOT NULL,
  mode        ENUM('cash','upi','card','bank','other') NOT NULL DEFAULT 'cash',
  reference   VARCHAR(120) NULL,
  paid_at     DATETIME NOT NULL,
  source      ENUM('staff','self') NOT NULL DEFAULT 'staff',
  status      ENUM('pending','verified','rejected') NOT NULL DEFAULT 'verified',
  received_by INT NULL,
  verified_by INT NULL,
  notes       VARCHAR(255) NULL,
  INDEX (invoice_id), INDEX (status), INDEX (paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'leads' => "CREATE TABLE IF NOT EXISTS leads (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  phone         VARCHAR(20) NOT NULL,
  email         VARCHAR(150) NULL,
  city          VARCHAR(80) NULL,
  source        VARCHAR(60) NULL,
  interest      ENUM('child','adult','both','unknown') NOT NULL DEFAULT 'unknown',
  plan_interest VARCHAR(120) NULL,
  status        ENUM('new','contacted','follow_up','visit_scheduled','converted','lost') NOT NULL DEFAULT 'new',
  assigned_to   INT NULL,
  next_followup DATE NULL,
  last_contact  DATETIME NULL,
  lost_reason   VARCHAR(255) NULL,
  customer_id   INT NULL,
  notes         TEXT NULL,
  created_by    INT NULL,
  created_at    DATETIME NOT NULL,
  INDEX (status), INDEX (phone), INDEX (assigned_to), INDEX (next_followup)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'lead_activities' => "CREATE TABLE IF NOT EXISTS lead_activities (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  lead_id    INT NOT NULL,
  kind       VARCHAR(40) NOT NULL,
  note       TEXT NULL,
  user_id    INT NULL,
  created_at DATETIME NOT NULL,
  INDEX (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'activity_log' => "CREATE TABLE IF NOT EXISTS activity_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  entity     VARCHAR(40) NOT NULL,
  entity_id  INT NOT NULL,
  action     VARCHAR(60) NOT NULL,
  note       VARCHAR(255) NULL,
  user_id    INT NULL,
  created_at DATETIME NOT NULL,
  INDEX (entity, entity_id), INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

/** Seed rows for a fresh install. */
function seed_data(PDO $pdo): void
{
    // --- Settings ---
    $defaults = [
        'academy_name'    => 'Stallion Horse Riding Academy',
        'academy_short'   => 'SHRA',
        'academy_address' => 'Sy 41 & 42, Kokapet Village, Gandipet, Telangana 500075',
        'academy_phone'   => '+91 99084 80010',
        'academy_email'   => 'info@stallionhorseriding.com',
        'academy_website' => 'www.stallionhorseriding.com',
        'academy_instagram' => 'stallionhorseridingacademy',
        'invoice_prefix'  => 'SHRA',
        'tax_pct'         => '0',
        'tax_label'       => 'GST',
        'upi_id'          => '',
        'upi_payee'       => 'Stallion Horse Riding Academy',
        'self_billing'    => '1',
        'terms'           => "1. Fees once paid are non-refundable and non-transferable.\n2. Riders must wear a helmet and proper footwear at all times.\n3. Sessions missed without 24 hours notice are treated as used.\n4. The academy is not liable for injuries arising from failure to follow trainer instructions.",
        'site_url'        => '',
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO settings (skey, svalue) VALUES (?,?)');
    foreach ($defaults as $k => $v) $st->execute([$k, $v]);

    // --- Plans from the academy fee card (30% offer pricing) ---
    if (!$pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn()) {
        $plans = [
            // name, audience, kind, sessions, dur, original, disc, amount, validity, order
            ['Guest Ride - Child',        'child', 'guest',   1,   20, 1000,   30,   700,   1,  1],
            ['2 Weeks Package - Child',   'child', 'package', 8,   30, 8000,   30,  5600,  14,  2],
            ['Monthly - Child',           'child', 'package', 16,  30, 14400,  30, 10080,  30,  3],
            ['Quarterly - Child',         'child', 'package', 48,  30, 38400,  30, 26880,  92,  4],
            ['Annual Package - Child',    'child', 'package', 192, 30, 134400, 30, 94080, 365,  5],
            ['Guest Ride - Adult',        'adult', 'guest',   1,   20, 1200,   30,   840,   1,  6],
            ['2 Weeks Package - Adult',   'adult', 'package', 8,   30, 9600,   30,  6720,  14,  7],
            ['Monthly - Adult',           'adult', 'package', 16,  30, 17600,  30, 12320,  30,  8],
            ['Quarterly - Adult',         'adult', 'package', 48,  30, 48000,  30, 33600,  92,  9],
            ['Annual Package - Adult',    'adult', 'package', 192, 30, 153600, 30, 107520, 365, 10],
        ];
        $st = $pdo->prepare('INSERT INTO plans
            (name,audience,kind,sessions,duration_min,original_amt,discount_pct,amount,validity_days,sort_order,status,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,"active",NOW())');
        foreach ($plans as $p) $st->execute($p);
    }
}

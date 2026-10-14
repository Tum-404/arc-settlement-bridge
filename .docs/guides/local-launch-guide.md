# Local Launch & Development Guide

This guide details how to launch and operate the **Arc Settlement Bridge** locally for testing, development, and hackathon judging demonstrations.

---

## 1. Quick Overview: Launch Modes

You can run the Settlement Bridge in two ways depending on what you want to test:

| Mode | Use Case | Requirements | Startup Speed |
| :--- | :--- | :--- | :--- |
| **Mode A: Full Docker Stack** | End-to-end integration testing with real **SolidInvoice** ERP, PostgreSQL 17, background workers, and live web UI. | Docker Desktop 4+ | ~1-2 min (includes container boot) |
| **Mode B: Native Host Stack** | Instant local development, unit/feature test suite execution, state machine debugging, and Naughty Agent simulations with zero external dependencies. | PHP 8.2+, Composer, Node.js | Instant (< 5 sec) |

---

## 2. Mode A: Full Multi-Service Docker Stack

The multi-service Docker Compose configuration launches the entire architecture in an isolated local network.

### Step 1: Start Docker Desktop
Ensure Docker Desktop is running on your machine.

### Step 2: Launch the Stack
Run the local Docker Compose configuration from the repository root:

```bash
docker compose -f docker-compose.local.yml up --build
```

### Services Provisioned

| Service | Internal Host | Host URL / Port | Description |
| :--- | :--- | :--- | :--- |
| **`bridge`** | `bridge:8000` | [http://localhost:8000](http://localhost:8000) | Laravel Settlement Bridge web UI & REST API |
| **`solidinvoice`** | `solidinvoice:8765` | [http://localhost:8765](http://localhost:8765) | SolidInvoice open-source ERP system |
| **`db`** | `db:5432` | `localhost:5432` | PostgreSQL 17 hosting `settlement` and `solidinvoice` databases |
| **`poller`** | `poller` | *(Internal)* | Scheduled background worker syncing payable invoices every minute |
| **`queue`** | `queue` | *(Internal)* | Asynchronous queue worker for background processing |

### Step 3: Complete First-Time SolidInvoice Setup
1. Open [http://localhost:8765](http://localhost:8765) in your web browser.
2. In the setup wizard:
   - **Database**: Leave database credentials untouched. The environment automatically connects to PostgreSQL `solidinvoice`. **Do not select SQLite.**
   - **Admin User**: Create your admin email and password.
3. Once logged into the SolidInvoice dashboard:
   - Go to **Settings** (`⚙️`) → **API Tokens** → **Create Token**.
   - Copy the generated API token.
   - Go to **Settings** → **Custom Fields** → **Client** or **Invoice** → Add a custom field named `crypto_address`.
   - Create a test Client with name `Acme Corporation` and assign their `crypto_address` to your recipient testnet wallet (e.g. `0x71C836643F37D110e49952042125585097486eF8`).
   - Create an invoice for that client in `Pending` status.

### Step 4: Restart the Bridge with your SolidInvoice Token
In PowerShell:
```powershell
$env:SOLIDINVOICE_TOKEN = "your_copied_api_token"
docker compose -f docker-compose.local.yml up -d bridge poller
```

Or in Bash:
```bash
SOLIDINVOICE_TOKEN="your_copied_api_token" docker compose -f docker-compose.local.yml up -d bridge poller
```

The Bridge will now automatically poll SolidInvoice, display bills on the dashboard at [http://localhost:8000](http://localhost:8000), and execute settlements.

---

## 3. Mode B: Native Host Quickstart (Zero-Dependency Offline Development)

For rapid iteration without Docker or external network calls:

### Step 1: Install Dependencies
```bash
composer install
npm install
```

### Step 2: Configure Environment
Copy `.env.example` to `.env`:
```bash
cp .env.example .env
php artisan key:generate
```

For zero-dependency SQLite storage on the host, set in `.env`:
```env
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
SETTLEMENT_PROVIDER=fake
INVOICE_GATEWAY=fake
```

Create the SQLite database file and run migrations with seeders:
```bash
touch database/database.sqlite
php artisan migrate --seed
```

### Step 3: Start Development Servers
In Terminal 1 (Vite frontend compiler):
```bash
npm run dev
```

In Terminal 2 (Laravel API & Web UI):
```bash
php artisan serve --port=8000
```

Open [http://localhost:8000](http://localhost:8000) to access the interactive Settlement Bridge Dashboard.

---

## 4. Running the Naughty Agent Simulator

The Naughty Agent CLI simulates rogue AI agent loops, duplicate requests, network timeouts, and recovery:

### 1. Duplicate Request Storm (3x Identical Submissions)
```bash
php artisan settlements:naughty-agent INV-001 --duplicate=3 --gateway=fake --provider=fake
```
*Expected Output:*
- 1 settlement created in the database.
- 2 duplicate requests intercepted and rejected.
- 2 `DUPLICATE_REQUEST_RECEIVED` audit records appended.

### 2. Network Timeout & Recovery Simulation
```bash
php artisan settlements:naughty-agent INV-002 --timeout-simulation --recover --gateway=fake --provider=fake
```
*Expected Output:*
- Provider call times out.
- Settlement status transitions to `SUBMISSION_UNKNOWN` (never premature `FAILED`).
- Recovery command runs deterministically and reconciles to `SUBMITTED` with `PAYMENT_SUBMITTED (recovered: true)` audit event.

### 3. Using the Shell Wrappers
- **PowerShell**:
  ```powershell
  .\scripts\naughty-agent.ps1 -Invoice INV-001 -Duplicate 5
  ```
- **Bash**:
  ```bash
  ./scripts/naughty-agent.sh INV-001 5
  ```

---

## 5. Connecting to Live Arc Testnet (Circle Developer-Controlled Wallets)

When ready to test live on-chain USDC disbursements on Circle Arc Testnet:

### Step 1: Configure Credentials in `.env`
```env
SETTLEMENT_PROVIDER=arc
CIRCLE_BLOCKCHAIN=ARC-TESTNET
CIRCLE_API_BASE_URL=https://api.circle.com
CIRCLE_API_KEY=your_circle_api_key
CIRCLE_ENTITY_SECRET=your_32_byte_hex_entity_secret
ARC_WALLET_ID=your_circle_wallet_id
ARC_WALLET_ADDRESS=your_wallet_1_address
```

### Step 2: Verify Circle API Connectivity
Run the built-in diagnostic command:
```bash
php artisan settlements:circle-check
```
*Checks:*
1. API Key authorization with Circle API.
2. RSA-OAEP SHA-256 entity secret encryption.
3. Arc Testnet wallet lookup and on-chain address confirmation.

> [!NOTE]
> On Windows host machines without a configured root CA bundle, direct outbound PHP calls to `api.circle.com` may encounter `cURL error 60`. Run live Circle interactions inside Docker Compose (Mode A) where Linux CA root certificates are pre-installed.

---

## 6. Running the Automated Test Suite

Run the full automated Pest test suite covering all Acceptance Criteria (AC1.1 through AC4.5):

```bash
php artisan test
```

To run only the Acceptance Criteria test suite:
```bash
php artisan test --filter AcceptanceCriteriaTest
```

To run code formatting checks:
```bash
./vendor/bin/pint --test
```

---

## 7. Operational Troubleshooting

### Resetting Demo Data
- Click **Reset Demo** in the top-right corner of the dashboard at [http://localhost:8000](http://localhost:8000).
- Or run:
  ```bash
  php artisan migrate:fresh --seed
  ```

### Live Structured Logs
Follow real-time settlement and reconciliation logs:
```bash
tail -f storage/logs/settlement-*.log
```
Or inside Docker:
```bash
docker compose -f docker-compose.local.yml exec bridge tail -f storage/logs/settlement-*.log
```

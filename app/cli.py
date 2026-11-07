"""أوامر مساعدة: تهيئة الجداول وتعبئة بيانات تجريبية.

    python -m app.cli init-db
    python -m app.cli seed
    python -m app.cli migrate [--apply-to-real-database]
"""

import sys
from pathlib import Path

from .config import settings
from .db import transaction

ROOT = Path(__file__).resolve().parent.parent
SCHEMA_FILE = ROOT / "sql" / "schema.sql"
MIGRATION_FILE = ROOT / "sql" / "migrations" / "001_reservation_key.sql"
REAL_DATABASE_FLAG = "--apply-to-real-database"

# بيانات تجريبية فقط: product_id هون وهمية، ما بتقرا من مشروع الزميل
SAMPLE_STOCK = {1: 10, 2: 5, 3: 0}


def _statements(path):
    lines = [
        line for line in path.read_text(encoding="utf-8").splitlines()
        if not line.strip().startswith("--")
    ]
    return [s for s in (part.strip() for part in "\n".join(lines).split(";")) if s]


def init_db():
    with transaction() as cur:
        for statement in _statements(SCHEMA_FILE):
            cur.execute(statement)
    print("schema ready")


def seed():
    with transaction() as cur:
        for product_id, quantity in SAMPLE_STOCK.items():
            cur.execute(
                "INSERT IGNORE INTO stock (product_id, quantity) VALUES (%s, %s)",
                (product_id, quantity),
            )
    print("sample stock seeded")


def migrate(flags):
    if not settings.db_name.endswith("_test") and REAL_DATABASE_FLAG not in flags:
        print(f"refusing to change '{settings.db_name}' without {REAL_DATABASE_FLAG}")
        sys.exit(1)

    with transaction() as cur:
        cur.execute(
            "SELECT COUNT(*) AS found FROM information_schema.columns "
            "WHERE table_schema = DATABASE() AND table_name = 'reservations' "
            "AND column_name = 'reservation_key'"
        )
        if cur.fetchone()["found"]:
            print(f"'{settings.db_name}' is already migrated")
            return
        for statement in _statements(MIGRATION_FILE):
            cur.execute(statement)
    print(f"'{settings.db_name}' migrated")


if __name__ == "__main__":
    command = sys.argv[1] if len(sys.argv) > 1 else ""
    if command == "init-db":
        init_db()
    elif command == "seed":
        seed()
    elif command == "migrate":
        migrate(sys.argv[2:])
    else:
        print(f"usage: python -m app.cli init-db | seed | migrate [{REAL_DATABASE_FLAG}]")
        sys.exit(1)

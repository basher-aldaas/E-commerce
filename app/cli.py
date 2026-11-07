"""أوامر مساعدة: تهيئة الجداول وتعبئة بيانات تجريبية.

    python -m app.cli init-db
    python -m app.cli seed
"""

import sys
from pathlib import Path

from .db import transaction

SCHEMA_FILE = Path(__file__).resolve().parent.parent / "sql" / "schema.sql"

# بيانات تجريبية فقط: product_id هون وهمية، ما بتقرا من مشروع الزميل
SAMPLE_STOCK = {1: 10, 2: 5, 3: 0}


def init_db():
    sql = SCHEMA_FILE.read_text(encoding="utf-8")
    statements = [s for s in (part.strip() for part in sql.split(";")) if s]
    with transaction() as cur:
        for statement in statements:
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


if __name__ == "__main__":
    command = sys.argv[1] if len(sys.argv) > 1 else ""
    if command == "init-db":
        init_db()
    elif command == "seed":
        seed()
    else:
        print("usage: python -m app.cli init-db | seed")
        sys.exit(1)

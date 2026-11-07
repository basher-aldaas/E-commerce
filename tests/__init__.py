"""Stops the test suite before it can reach a real database.

The tests delete every row in stock, reservations and reservation_items, so they
only run when the configured database name ends in `_test`.
"""

from app.config import settings

if not settings.db_name.endswith("_test"):
    raise RuntimeError(
        f"Refusing to run tests on database '{settings.db_name}'. "
        "Set WAREHOUSE_DB_NAME to a database whose name ends in _test."
    )

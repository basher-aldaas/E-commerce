from contextlib import contextmanager

import pymysql
from pymysql.cursors import DictCursor

from .config import settings


def _connect():
    return pymysql.connect(
        host=settings.db_host,
        port=settings.db_port,
        user=settings.db_user,
        password=settings.db_password,
        database=settings.db_name,
        charset="utf8mb4",
        cursorclass=DictCursor,
        autocommit=False,
    )


@contextmanager
def transaction():
    """يفتح transaction ويعمل commit عند النجاح، و rollback عند أي خطأ."""
    conn = _connect()
    try:
        cursor = conn.cursor()
        yield cursor
        conn.commit()
    except BaseException:
        conn.rollback()
        raise
    finally:
        conn.close()

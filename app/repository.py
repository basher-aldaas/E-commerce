"""كل استعلامات SQL هنا. كل دالة تاخد cursor ضمن transaction."""

RESERVED = "RESERVED"
CONFIRMED = "CONFIRMED"
RELEASED = "RELEASED"
RETURNED = "RETURNED"


def get_quantities(cur, product_ids):
    if not product_ids:
        return {}
    placeholders = ",".join(["%s"] * len(product_ids))
    cur.execute(
        f"SELECT product_id, quantity FROM stock WHERE product_id IN ({placeholders})",
        list(product_ids),
    )
    return {row["product_id"]: row["quantity"] for row in cur.fetchall()}


def decrement_if_enough(cur, product_id, quantity) -> bool:
    # الشرط داخل الـ UPDATE نفسه: هيك الفحص والخصم صاروا عملية وحدة ذرية
    cur.execute(
        "UPDATE stock SET quantity = quantity - %s "
        "WHERE product_id = %s AND quantity >= %s",
        (quantity, product_id, quantity),
    )
    return cur.rowcount == 1


def increment(cur, product_id, quantity):
    cur.execute(
        "UPDATE stock SET quantity = quantity + %s WHERE product_id = %s",
        (quantity, product_id),
    )


def insert_stock(cur, product_id, quantity):
    cur.execute(
        "INSERT INTO stock (product_id, quantity) VALUES (%s, %s)",
        (product_id, quantity),
    )


def set_stock(cur, product_id, quantity):
    cur.execute(
        "INSERT INTO stock (product_id, quantity) VALUES (%s, %s) "
        "ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)",
        (product_id, quantity),
    )


def insert_reservation(cur, reservation_key, status=RESERVED) -> int:
    cur.execute(
        "INSERT INTO reservations (reservation_key, status) VALUES (%s, %s)",
        (reservation_key, status),
    )
    return cur.lastrowid


def insert_item(cur, reservation_id, product_id, quantity):
    cur.execute(
        "INSERT INTO reservation_items (reservation_id, product_id, quantity) "
        "VALUES (%s, %s, %s)",
        (reservation_id, product_id, quantity),
    )


def get_reservation(cur, reservation_key):
    cur.execute(
        "SELECT id, reservation_key, status FROM reservations WHERE reservation_key = %s",
        (reservation_key,),
    )
    return cur.fetchone()


def get_reservation_for_update(cur, reservation_key):
    cur.execute(
        "SELECT id, reservation_key, status FROM reservations "
        "WHERE reservation_key = %s FOR UPDATE",
        (reservation_key,),
    )
    return cur.fetchone()


def get_items(cur, reservation_id):
    # Sorted so that restoring stock locks rows in the same order as reserving it.
    cur.execute(
        "SELECT product_id, quantity FROM reservation_items "
        "WHERE reservation_id = %s ORDER BY product_id",
        (reservation_id,),
    )
    return cur.fetchall()


def confirm_if_reserved(cur, reservation_key) -> bool:
    cur.execute(
        "UPDATE reservations SET status = %s WHERE reservation_key = %s AND status = %s",
        (CONFIRMED, reservation_key, RESERVED),
    )
    return cur.rowcount == 1


def set_status(cur, reservation_id, status):
    cur.execute(
        "UPDATE reservations SET status = %s WHERE id = %s",
        (status, reservation_id),
    )

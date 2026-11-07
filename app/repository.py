"""كل استعلامات SQL هنا. كل دالة تاخد cursor ضمن transaction."""

RESERVED = "RESERVED"
CONFIRMED = "CONFIRMED"
RELEASED = "RELEASED"


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


def insert_reservation(cur, order_id) -> int:
    cur.execute(
        "INSERT INTO reservations (order_id, status) VALUES (%s, %s)",
        (order_id, RESERVED),
    )
    return cur.lastrowid


def insert_item(cur, reservation_id, product_id, quantity):
    cur.execute(
        "INSERT INTO reservation_items (reservation_id, product_id, quantity) "
        "VALUES (%s, %s, %s)",
        (reservation_id, product_id, quantity),
    )


def get_reservation(cur, order_id):
    cur.execute(
        "SELECT id, order_id, status FROM reservations WHERE order_id = %s",
        (order_id,),
    )
    return cur.fetchone()


def get_reservation_for_update(cur, order_id):
    cur.execute(
        "SELECT id, order_id, status FROM reservations WHERE order_id = %s FOR UPDATE",
        (order_id,),
    )
    return cur.fetchone()


def get_items(cur, reservation_id):
    cur.execute(
        "SELECT product_id, quantity FROM reservation_items WHERE reservation_id = %s",
        (reservation_id,),
    )
    return cur.fetchall()


def confirm_if_reserved(cur, order_id) -> bool:
    cur.execute(
        "UPDATE reservations SET status = %s WHERE order_id = %s AND status = %s",
        (CONFIRMED, order_id, RESERVED),
    )
    return cur.rowcount == 1


def set_status(cur, reservation_id, status):
    cur.execute(
        "UPDATE reservations SET status = %s WHERE id = %s",
        (status, reservation_id),
    )

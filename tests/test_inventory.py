"""اختبارات على قاعدة بيانات حقيقية (MySQL). شغّلها على قاعدة اختبار منفصلة."""

import unittest
import uuid
from concurrent.futures import ThreadPoolExecutor

from app import repository as repo
from app import inventory_service as inv
from app.db import transaction
from app.inventory_service import InvalidRequest, InventoryService

P1, P2, P3 = 101, 102, 103  # منتجات وهمية للاختبار


def new_key():
    return str(uuid.uuid4())


def reset_tables(stock):
    with transaction() as cur:
        cur.execute("DELETE FROM reservation_items")
        cur.execute("DELETE FROM reservations")
        cur.execute("DELETE FROM stock")
        for pid, qty in stock.items():
            cur.execute("INSERT INTO stock (product_id, quantity) VALUES (%s, %s)", (pid, qty))


def quantities(*pids):
    with transaction() as cur:
        found = repo.get_quantities(cur, list(pids))
    return [found[p] for p in pids]


def status_of(key):
    with transaction() as cur:
        return repo.get_reservation(cur, key)["status"]


class GetStockTests(unittest.TestCase):
    def setUp(self):
        self.svc = InventoryService()

    def test_reports_quantity_and_whether_the_product_is_tracked(self):
        reset_tables({P1: 7, P2: 0})
        levels = self.svc.get_stock([P1, P2, 999])
        self.assertEqual((levels[P1].quantity, levels[P1].tracked), (7, True))
        self.assertEqual((levels[P2].quantity, levels[P2].tracked), (0, True))
        self.assertEqual((levels[999].quantity, levels[999].tracked), (0, False))


class ReserveTests(unittest.TestCase):
    def setUp(self):
        self.svc = InventoryService()

    def test_reserve_decrements_stock(self):
        reset_tables({P1: 10})
        result = self.svc.reserve(new_key(), [(P1, 3)])
        self.assertTrue(result.success)
        self.assertEqual(result.code, inv.OK)
        self.assertEqual(quantities(P1), [7])

    def test_insufficient_stock_is_all_or_nothing(self):
        reset_tables({P1: 5, P2: 1})
        key = new_key()
        result = self.svc.reserve(key, [(P1, 2), (P2, 2)])
        self.assertFalse(result.success)
        self.assertEqual(result.code, inv.INSUFFICIENT_STOCK)
        self.assertEqual(result.shortage, inv.Shortage(P2, 2, 1))
        self.assertEqual(quantities(P1, P2), [5, 1])  # ما انخصم شي
        with transaction() as cur:
            self.assertIsNone(repo.get_reservation(cur, key))

    def test_untracked_product_is_rejected_with_its_own_code(self):
        reset_tables({P1: 5})
        result = self.svc.reserve(new_key(), [(P1, 1), (999, 1)])
        self.assertFalse(result.success)
        self.assertEqual(result.code, inv.PRODUCT_NOT_FOUND)
        self.assertEqual(result.shortage, inv.Shortage(999, 1, 0))
        self.assertEqual(quantities(P1), [5])

    def test_duplicate_reserve_is_idempotent(self):
        reset_tables({P1: 10})
        key = new_key()
        self.assertTrue(self.svc.reserve(key, [(P1, 4)]).success)
        again = self.svc.reserve(key, [(P1, 4)])
        self.assertTrue(again.success)
        self.assertEqual(again.reason, "already reserved")
        self.assertEqual(quantities(P1), [6])  # انخصم مرة وحدة بس

    def test_same_key_with_different_items_is_rejected(self):
        reset_tables({P1: 10, P2: 10})
        key = new_key()
        self.svc.reserve(key, [(P1, 4)])

        for different in ([(P1, 5)], [(P2, 4)], [(P1, 4), (P2, 1)]):
            result = self.svc.reserve(key, different)
            self.assertFalse(result.success)
            self.assertEqual(result.code, inv.KEY_CONFLICT)
        self.assertEqual(quantities(P1, P2), [6, 10])

    def test_same_items_in_any_order_or_split_still_match(self):
        reset_tables({P1: 10, P2: 10})
        key = new_key()
        self.svc.reserve(key, [(P1, 4), (P2, 1)])
        self.assertTrue(self.svc.reserve(key, [(P2, 1), (P1, 1), (P1, 3)]).success)
        self.assertEqual(quantities(P1, P2), [6, 9])

    def test_invalid_input_is_rejected(self):
        reset_tables({P1: 10})
        for key, items in (("not-a-uuid", [(P1, 1)]), ("", [(P1, 1)]), (new_key(), []), (new_key(), [(P1, 0)])):
            with self.assertRaises(InvalidRequest):
                self.svc.reserve(key, items)
        self.assertEqual(quantities(P1), [10])

    def test_uppercase_key_is_the_same_reservation(self):
        reset_tables({P1: 10})
        key = new_key()
        self.svc.reserve(key.upper(), [(P1, 1)])
        self.assertTrue(self.svc.reserve(key, [(P1, 1)]).success)
        self.assertEqual(quantities(P1), [9])


class ConfirmAndReleaseTests(unittest.TestCase):
    def setUp(self):
        self.svc = InventoryService()

    def test_confirm_is_idempotent(self):
        reset_tables({P1: 10})
        key = new_key()
        self.svc.reserve(key, [(P1, 2)])
        self.assertTrue(self.svc.confirm(key).success)
        self.assertEqual(self.svc.confirm(key).reason, "already confirmed")
        self.assertEqual(status_of(key), repo.CONFIRMED)
        self.assertEqual(quantities(P1), [8])

    def test_confirm_then_release_is_refused(self):
        reset_tables({P1: 10})
        key = new_key()
        self.svc.reserve(key, [(P1, 2)])
        self.svc.confirm(key)
        release = self.svc.release(key)
        self.assertFalse(release.success)
        self.assertEqual(release.code, inv.INVALID_STATE)
        self.assertEqual(quantities(P1), [8])  # الكمية ما رجعت

    def test_release_restores_stock_from_reserved(self):
        reset_tables({P1: 10, P2: 5})
        key = new_key()
        self.svc.reserve(key, [(P1, 3), (P2, 2)])
        self.assertTrue(self.svc.release(key).success)
        self.assertEqual(quantities(P1, P2), [10, 5])
        self.assertEqual(self.svc.release(key).reason, "already released")  # idempotent
        self.assertEqual(quantities(P1, P2), [10, 5])

    def test_confirm_after_release_fails(self):
        reset_tables({P1: 10})
        key = new_key()
        self.svc.reserve(key, [(P1, 1)])
        self.svc.release(key)
        confirm = self.svc.confirm(key)
        self.assertFalse(confirm.success)
        self.assertEqual(confirm.code, inv.ALREADY_RELEASED)

    def test_confirm_of_unknown_key_fails(self):
        reset_tables({P1: 10})
        confirm = self.svc.confirm(new_key())
        self.assertFalse(confirm.success)
        self.assertEqual(confirm.code, inv.RESERVATION_NOT_FOUND)

    def test_late_reserve_after_release_cannot_take_stock(self):
        """Client timed out, released, and then the delayed Reserve arrives."""
        reset_tables({P1: 10})
        key = new_key()

        self.assertTrue(self.svc.release(key).success)
        self.assertEqual(status_of(key), repo.RELEASED)

        late = self.svc.reserve(key, [(P1, 2)])
        self.assertFalse(late.success)
        self.assertEqual(late.code, inv.ALREADY_RELEASED)
        self.assertEqual(quantities(P1), [10])

    def test_release_of_unknown_key_is_idempotent(self):
        reset_tables({P1: 10})
        key = new_key()
        self.assertTrue(self.svc.release(key).success)
        self.assertTrue(self.svc.release(key).success)
        self.assertEqual(quantities(P1), [10])


class ReturnStockTests(unittest.TestCase):
    def setUp(self):
        self.svc = InventoryService()

    def confirmed(self, items):
        key = new_key()
        self.svc.reserve(key, items)
        self.svc.confirm(key)
        return key

    def test_return_restores_a_confirmed_reservation(self):
        reset_tables({P1: 10, P2: 5})
        key = self.confirmed([(P1, 3), (P2, 2)])
        self.assertEqual(quantities(P1, P2), [7, 3])

        result = self.svc.return_stock(key)

        self.assertTrue(result.success)
        self.assertEqual(status_of(key), repo.RETURNED)
        self.assertEqual(quantities(P1, P2), [10, 5])

    def test_return_is_idempotent(self):
        reset_tables({P1: 10})
        key = self.confirmed([(P1, 3)])
        self.svc.return_stock(key)
        self.assertEqual(self.svc.return_stock(key).reason, "already returned")
        self.assertEqual(quantities(P1), [10])

    def test_return_refuses_an_unconfirmed_reservation(self):
        reset_tables({P1: 10})
        key = new_key()
        self.svc.reserve(key, [(P1, 3)])
        result = self.svc.return_stock(key)
        self.assertFalse(result.success)
        self.assertEqual(result.code, inv.INVALID_STATE)
        self.assertEqual(status_of(key), repo.RESERVED)
        self.assertEqual(quantities(P1), [7])

    def test_return_refuses_a_released_reservation(self):
        reset_tables({P1: 10})
        key = new_key()
        self.svc.reserve(key, [(P1, 3)])
        self.svc.release(key)
        self.assertEqual(self.svc.return_stock(key).code, inv.INVALID_STATE)
        self.assertEqual(quantities(P1), [10])

    def test_return_of_unknown_key_fails(self):
        reset_tables({P1: 10})
        result = self.svc.return_stock(new_key())
        self.assertFalse(result.success)
        self.assertEqual(result.code, inv.RESERVATION_NOT_FOUND)

    def test_returned_reservation_cannot_be_used_again(self):
        reset_tables({P1: 10})
        key = self.confirmed([(P1, 3)])
        self.svc.return_stock(key)

        self.assertEqual(self.svc.release(key).code, inv.INVALID_STATE)
        self.assertEqual(self.svc.confirm(key).code, inv.INVALID_STATE)
        self.assertEqual(self.svc.reserve(key, [(P1, 3)]).code, inv.INVALID_STATE)
        self.assertEqual(quantities(P1), [10])


class StockSetupTests(unittest.TestCase):
    def setUp(self):
        self.svc = InventoryService()

    def test_initialize_creates_a_row(self):
        reset_tables({})
        result = self.svc.initialize_stock(P1, 12)
        self.assertTrue(result.success)
        self.assertEqual(result.quantity, 12)
        self.assertEqual(quantities(P1), [12])

    def test_initialize_never_overwrites(self):
        reset_tables({P1: 8})
        result = self.svc.initialize_stock(P1, 50)
        self.assertFalse(result.success)
        self.assertEqual(result.code, inv.ALREADY_INITIALIZED)
        self.assertEqual(result.quantity, 8)
        self.assertEqual(quantities(P1), [8])

    def test_initialize_with_zero_is_tracked_as_sold_out(self):
        reset_tables({})
        self.assertTrue(self.svc.initialize_stock(P1, 0).success)
        self.assertTrue(self.svc.get_stock([P1])[P1].tracked)

    def test_set_stock_overwrites_or_creates(self):
        reset_tables({P1: 8})
        self.assertEqual(self.svc.set_stock(P1, 50).quantity, 50)
        self.assertEqual(self.svc.set_stock(P2, 3).quantity, 3)
        self.assertEqual(quantities(P1, P2), [50, 3])

    def test_negative_quantities_are_rejected(self):
        reset_tables({P1: 8})
        with self.assertRaises(InvalidRequest):
            self.svc.initialize_stock(P2, -1)
        with self.assertRaises(InvalidRequest):
            self.svc.set_stock(P1, -1)
        self.assertEqual(quantities(P1), [8])

    def test_invalid_product_ids_are_rejected(self):
        with self.assertRaises(InvalidRequest):
            self.svc.initialize_stock(0, 1)
        with self.assertRaises(InvalidRequest):
            self.svc.set_stock(-1, 1)


class ConcurrencyTests(unittest.TestCase):
    def setUp(self):
        self.svc = InventoryService()

    def test_race_only_one_order_wins_last_unit(self):
        reset_tables({P3: 1})
        with ThreadPoolExecutor(max_workers=10) as pool:
            results = list(pool.map(
                lambda _: self.svc.reserve(new_key(), [(P3, 1)]), range(10)
            ))
        winners = [r for r in results if r.success]
        self.assertEqual(len(winners), 1)
        self.assertEqual(quantities(P3), [0])

    def test_race_same_key_is_reserved_once(self):
        reset_tables({P1: 10})
        key = new_key()
        with ThreadPoolExecutor(max_workers=10) as pool:
            results = list(pool.map(lambda _: self.svc.reserve(key, [(P1, 1)]), range(10)))
        self.assertTrue(all(r.success for r in results))
        self.assertEqual(quantities(P1), [9])

    def test_race_opposite_item_order_does_not_lose_stock(self):
        reset_tables({P1: 50, P2: 50})

        def reserve(index):
            items = [(P1, 1), (P2, 1)] if index % 2 else [(P2, 1), (P1, 1)]
            return self.svc.reserve(new_key(), items)

        with ThreadPoolExecutor(max_workers=10) as pool:
            results = list(pool.map(reserve, range(20)))
        self.assertTrue(all(r.success for r in results))
        self.assertEqual(quantities(P1, P2), [30, 30])

    def test_race_reserve_and_release_of_same_key_never_leaks_stock(self):
        for _ in range(10):
            reset_tables({P1: 10})
            key = new_key()
            with ThreadPoolExecutor(max_workers=2) as pool:
                reserve = pool.submit(self.svc.reserve, key, [(P1, 2)])
                release = pool.submit(self.svc.release, key)
                reserve.result()
                self.assertTrue(release.result().success)

            # Whichever ran first, the key ends released and the stock is whole.
            self.assertEqual(status_of(key), repo.RELEASED)
            self.assertEqual(quantities(P1), [10])

    def test_race_concurrent_returns_restore_once(self):
        reset_tables({P1: 10})
        key = new_key()
        self.svc.reserve(key, [(P1, 4)])
        self.svc.confirm(key)
        with ThreadPoolExecutor(max_workers=10) as pool:
            results = list(pool.map(lambda _: self.svc.return_stock(key), range(10)))
        self.assertTrue(all(r.success for r in results))
        self.assertEqual(quantities(P1), [10])

    def test_race_concurrent_initialize_creates_one_row(self):
        reset_tables({})
        with ThreadPoolExecutor(max_workers=10) as pool:
            results = list(pool.map(lambda q: self.svc.initialize_stock(P1, q), range(1, 11)))
        winners = [r for r in results if r.success]
        self.assertEqual(len(winners), 1)
        self.assertEqual(quantities(P1), [winners[0].quantity])


class LegacyRowTests(unittest.TestCase):
    def test_old_order_id_rows_are_not_touched_by_key_operations(self):
        reset_tables({P1: 10})
        with transaction() as cur:
            cur.execute("INSERT INTO reservations (order_id, status) VALUES (5001, 'CONFIRMED')")

        svc = InventoryService()
        key = new_key()
        self.assertTrue(svc.reserve(key, [(P1, 1)]).success)
        self.assertTrue(svc.release(key).success)

        with transaction() as cur:
            cur.execute("SELECT status, reservation_key FROM reservations WHERE order_id = 5001")
            row = cur.fetchone()
        self.assertEqual(row["status"], "CONFIRMED")
        self.assertIsNone(row["reservation_key"])


if __name__ == "__main__":
    unittest.main()

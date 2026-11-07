"""اختبارات على قاعدة بيانات حقيقية (MySQL). شغّلها على قاعدة اختبار منفصلة."""

import unittest
from concurrent.futures import ThreadPoolExecutor

from app import repository as repo
from app.db import transaction
from app.inventory_service import InventoryService

P1, P2, P3 = 101, 102, 103  # منتجات وهمية للاختبار


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


class InventoryTests(unittest.TestCase):
    def setUp(self):
        self.svc = InventoryService()

    def test_reserve_decrements_stock(self):
        reset_tables({P1: 10})
        result = self.svc.reserve(1001, [(P1, 3)])
        self.assertTrue(result.success)
        self.assertEqual(quantities(P1), [7])

    def test_insufficient_stock_is_all_or_nothing(self):
        reset_tables({P1: 5, P2: 1})
        result = self.svc.reserve(1002, [(P1, 2), (P2, 2)])
        self.assertFalse(result.success)
        self.assertIn("insufficient stock for product 102", result.reason)
        self.assertEqual(quantities(P1, P2), [5, 1])  # ما انخصم شي

    def test_duplicate_reserve_is_idempotent(self):
        reset_tables({P1: 10})
        self.assertTrue(self.svc.reserve(1003, [(P1, 4)]).success)
        again = self.svc.reserve(1003, [(P1, 4)])
        self.assertTrue(again.success)
        self.assertEqual(quantities(P1), [6])  # انخصم مرة وحدة بس

    def test_confirm_then_release_is_refused(self):
        reset_tables({P1: 10})
        self.svc.reserve(1004, [(P1, 2)])
        self.assertTrue(self.svc.confirm(1004).success)
        release = self.svc.release(1004)
        self.assertFalse(release.success)
        self.assertEqual(quantities(P1), [8])  # الكمية ما رجعت

    def test_release_restores_stock_from_reserved(self):
        reset_tables({P1: 10, P2: 5})
        self.svc.reserve(1005, [(P1, 3), (P2, 2)])
        self.assertTrue(self.svc.release(1005).success)
        self.assertEqual(quantities(P1, P2), [10, 5])
        self.assertTrue(self.svc.release(1005).success)  # idempotent

    def test_confirm_after_release_fails(self):
        reset_tables({P1: 10})
        self.svc.reserve(1006, [(P1, 1)])
        self.svc.release(1006)
        self.assertFalse(self.svc.confirm(1006).success)

    def test_unknown_order_confirm_and_release_fail(self):
        reset_tables({P1: 10})
        self.assertFalse(self.svc.confirm(9999).success)
        self.assertFalse(self.svc.release(9999).success)

    def test_get_stock_returns_zero_for_unknown_product(self):
        reset_tables({P1: 7})
        self.assertEqual(self.svc.get_stock([P1, 999]), {P1: 7, 999: 0})

    def test_race_only_one_order_wins_last_unit(self):
        reset_tables({P3: 1})
        with ThreadPoolExecutor(max_workers=10) as pool:
            results = list(pool.map(
                lambda oid: self.svc.reserve(oid, [(P3, 1)]), range(2001, 2011)
            ))
        winners = [r for r in results if r.success]
        self.assertEqual(len(winners), 1)
        self.assertEqual(quantities(P3), [0])

    def test_race_same_order_is_reserved_once(self):
        reset_tables({P1: 10})
        with ThreadPoolExecutor(max_workers=10) as pool:
            results = list(pool.map(lambda _: self.svc.reserve(3001, [(P1, 1)]), range(10)))
        self.assertTrue(all(r.success for r in results))
        self.assertEqual(quantities(P1), [9])


if __name__ == "__main__":
    unittest.main()

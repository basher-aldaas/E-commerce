"""منطق المستودع. ما فيه أي شي عن gRPC هون، فبنقدر نختبره لحاله."""

import logging
import time
import uuid
from dataclasses import dataclass
from typing import Optional

import pymysql

from . import repository as repo
from .db import transaction

log = logging.getLogger("warehouse.inventory")

DEADLOCK_ERROR = 1213
LOCK_WAIT_TIMEOUT_ERROR = 1205
DUPLICATE_KEY_ERROR = 1062
RETRYABLE_ERRORS = {DEADLOCK_ERROR, LOCK_WAIT_TIMEOUT_ERROR, DUPLICATE_KEY_ERROR}
MAX_ATTEMPTS = 3

# Result codes. The gRPC layer maps them to the ResultCode enum as RESULT_<code>.
OK = "OK"
INSUFFICIENT_STOCK = "INSUFFICIENT_STOCK"
PRODUCT_NOT_FOUND = "PRODUCT_NOT_FOUND"
RESERVATION_NOT_FOUND = "RESERVATION_NOT_FOUND"
KEY_CONFLICT = "KEY_CONFLICT"
ALREADY_RELEASED = "ALREADY_RELEASED"
INVALID_STATE = "INVALID_STATE"
ALREADY_INITIALIZED = "ALREADY_INITIALIZED"


class InvalidRequest(ValueError):
    """طلب مش صالح (مثلاً كمية سالبة). بيتحول لـ INVALID_ARGUMENT بالـ gRPC."""


@dataclass(frozen=True)
class Shortage:
    product_id: int
    requested: int
    available: int


@dataclass(frozen=True)
class Result:
    success: bool
    reason: str = ""
    code: str = OK
    shortage: Optional[Shortage] = None
    quantity: Optional[int] = None


@dataclass(frozen=True)
class StockLevel:
    quantity: int
    tracked: bool


class _Rejected(Exception):
    """رفض منطقي داخل transaction. بيعمل rollback ويرجع السبب للعميل."""

    def __init__(self, result: Result):
        super().__init__(result.reason)
        self.result = result


class InventoryService:
    # ---------- GetStock ----------
    def get_stock(self, product_ids) -> dict:
        ids = sorted({self._positive(pid, "product_id") for pid in product_ids})
        with transaction() as cur:
            found = repo.get_quantities(cur, ids)
        return {pid: StockLevel(found.get(pid, 0), pid in found) for pid in ids}

    # ---------- Reserve ----------
    def reserve(self, reservation_key: str, items) -> Result:
        key = self._key(reservation_key)
        merged = self._merge_items(items)
        return self._with_retry(lambda: self._reserve_once(key, merged))

    def _reserve_once(self, key, merged) -> Result:
        try:
            with transaction() as cur:
                try:
                    reservation_id = repo.insert_reservation(cur, key)
                except pymysql.err.IntegrityError as exc:
                    if exc.args[0] != DUPLICATE_KEY_ERROR:
                        raise
                    # الطلب محجوز قبل (idempotency): نرجع نفس النتيجة بدون خصم جديد
                    return self._existing_reservation_result(cur, key, merged)

                # ترتيب المنتجات ثابت لتفادي الـ deadlock بين طلبات متزامنة
                for product_id, quantity in sorted(merged.items()):
                    if not repo.decrement_if_enough(cur, product_id, quantity):
                        raise _Rejected(self._shortage_result(cur, product_id, quantity))

                for product_id, quantity in merged.items():
                    repo.insert_item(cur, reservation_id, product_id, quantity)
            return Result(True)
        except _Rejected as rejected:
            return rejected.result

    @staticmethod
    def _existing_reservation_result(cur, key, merged) -> Result:
        existing = repo.get_reservation(cur, key)
        if existing["status"] == repo.RELEASED:
            return Result(False, "reservation was already released", ALREADY_RELEASED)
        if existing["status"] == repo.RETURNED:
            return Result(False, "reservation stock was already returned", INVALID_STATE)

        stored = {i["product_id"]: i["quantity"] for i in repo.get_items(cur, existing["id"])}
        if stored != merged:
            return Result(False, "reservation key was used with different items", KEY_CONFLICT)
        return Result(True, "already reserved")

    @staticmethod
    def _shortage_result(cur, product_id, quantity) -> Result:
        available = repo.get_quantities(cur, [product_id]).get(product_id)
        if available is None:
            return Result(
                False,
                f"product {product_id} not found in stock",
                PRODUCT_NOT_FOUND,
                Shortage(product_id, quantity, 0),
            )
        return Result(
            False,
            f"insufficient stock for product {product_id}: "
            f"requested {quantity}, available {available}",
            INSUFFICIENT_STOCK,
            Shortage(product_id, quantity, available),
        )

    # ---------- Confirm ----------
    def confirm(self, reservation_key: str) -> Result:
        key = self._key(reservation_key)
        return self._with_retry(lambda: self._confirm_once(key))

    def _confirm_once(self, key) -> Result:
        with transaction() as cur:
            if repo.confirm_if_reserved(cur, key):
                return Result(True)
            current = repo.get_reservation(cur, key)

        if current is None:
            return Result(False, "reservation not found", RESERVATION_NOT_FOUND)
        if current["status"] == repo.CONFIRMED:
            return Result(True, "already confirmed")
        if current["status"] == repo.RELEASED:
            return Result(False, "reservation was already released", ALREADY_RELEASED)
        return Result(False, f"cannot confirm reservation in status {current['status']}", INVALID_STATE)

    # ---------- Release ----------
    def release(self, reservation_key: str) -> Result:
        key = self._key(reservation_key)
        return self._with_retry(lambda: self._release_once(key))

    def _release_once(self, key) -> Result:
        try:
            with transaction() as cur:
                reservation = repo.get_reservation_for_update(cur, key)
                if reservation is None:
                    # A Reserve may still be on its way after a client timeout.
                    # Recording the key makes that late Reserve fail, so it can't
                    # take stock that nothing will ever give back.
                    repo.insert_reservation(cur, key, repo.RELEASED)
                    return Result(True, "no reservation found, key recorded as released")
                if reservation["status"] == repo.RELEASED:
                    return Result(True, "already released")
                if reservation["status"] != repo.RESERVED:
                    # بعد التأكيد ما بنرجّع الكمية من هون: هاد شغل ReturnStock
                    raise _Rejected(Result(
                        False,
                        f"cannot release a reservation in status {reservation['status']}",
                        INVALID_STATE,
                    ))

                for item in repo.get_items(cur, reservation["id"]):
                    repo.increment(cur, item["product_id"], item["quantity"])
                repo.set_status(cur, reservation["id"], repo.RELEASED)
            return Result(True)
        except _Rejected as rejected:
            return rejected.result

    # ---------- ReturnStock ----------
    def return_stock(self, reservation_key: str) -> Result:
        key = self._key(reservation_key)
        return self._with_retry(lambda: self._return_once(key))

    def _return_once(self, key) -> Result:
        try:
            with transaction() as cur:
                reservation = repo.get_reservation_for_update(cur, key)
                if reservation is None:
                    raise _Rejected(Result(False, "reservation not found", RESERVATION_NOT_FOUND))
                if reservation["status"] == repo.RETURNED:
                    return Result(True, "already returned")
                if reservation["status"] != repo.CONFIRMED:
                    raise _Rejected(Result(
                        False,
                        f"cannot return stock of a reservation in status {reservation['status']}",
                        INVALID_STATE,
                    ))

                for item in repo.get_items(cur, reservation["id"]):
                    repo.increment(cur, item["product_id"], item["quantity"])
                repo.set_status(cur, reservation["id"], repo.RETURNED)
            return Result(True)
        except _Rejected as rejected:
            return rejected.result

    # ---------- InitializeStock ----------
    def initialize_stock(self, product_id: int, quantity: int) -> Result:
        product_id = self._positive(product_id, "product_id")
        quantity = self._non_negative(quantity, "quantity")

        with transaction() as cur:
            try:
                repo.insert_stock(cur, product_id, quantity)
            except pymysql.err.IntegrityError as exc:
                if exc.args[0] != DUPLICATE_KEY_ERROR:
                    raise
                existing = repo.get_quantities(cur, [product_id])[product_id]
                return Result(
                    False,
                    f"stock for product {product_id} is already initialized",
                    ALREADY_INITIALIZED,
                    quantity=existing,
                )
        return Result(True, quantity=quantity)

    # ---------- SetStock ----------
    def set_stock(self, product_id: int, quantity: int) -> Result:
        product_id = self._positive(product_id, "product_id")
        quantity = self._non_negative(quantity, "quantity")

        def work():
            with transaction() as cur:
                repo.set_stock(cur, product_id, quantity)
            return Result(True, quantity=quantity)

        return self._with_retry(work)

    # ---------- helpers ----------
    @staticmethod
    def _key(value) -> str:
        try:
            return str(uuid.UUID(str(value)))
        except ValueError:
            raise InvalidRequest("reservation_key must be a UUID")

    @staticmethod
    def _positive(value, name) -> int:
        try:
            number = int(value)
        except (TypeError, ValueError):
            raise InvalidRequest(f"{name} must be an integer")
        if number <= 0:
            raise InvalidRequest(f"{name} must be positive")
        return number

    @staticmethod
    def _non_negative(value, name) -> int:
        try:
            number = int(value)
        except (TypeError, ValueError):
            raise InvalidRequest(f"{name} must be an integer")
        if number < 0:
            raise InvalidRequest(f"{name} must not be negative")
        return number

    def _merge_items(self, items) -> dict:
        if not items:
            raise InvalidRequest("items must not be empty")
        merged: dict[int, int] = {}
        for item in items:
            product_id = self._positive(item[0], "product_id")
            quantity = self._positive(item[1], "quantity")
            merged[product_id] = merged.get(product_id, 0) + quantity
        return merged

    @staticmethod
    def _with_retry(work):
        for attempt in range(1, MAX_ATTEMPTS + 1):
            try:
                return work()
            except (pymysql.err.OperationalError, pymysql.err.IntegrityError) as exc:
                if exc.args[0] not in RETRYABLE_ERRORS or attempt == MAX_ATTEMPTS:
                    raise
                log.warning("database conflict %s, retrying (attempt %d)", exc.args[0], attempt)
                time.sleep(0.01 * attempt)

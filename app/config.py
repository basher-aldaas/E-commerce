import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Settings:
    db_host: str = os.getenv("WAREHOUSE_DB_HOST", "127.0.0.1")
    db_port: int = int(os.getenv("WAREHOUSE_DB_PORT", "3306"))
    db_user: str = os.getenv("WAREHOUSE_DB_USER", "warehouse")
    db_password: str = os.getenv("WAREHOUSE_DB_PASSWORD", "")
    db_name: str = os.getenv("WAREHOUSE_DB_NAME", "warehouse")
    db_connect_timeout: int = int(os.getenv("WAREHOUSE_DB_CONNECT_TIMEOUT", "2"))
    db_io_timeout: int = int(os.getenv("WAREHOUSE_DB_IO_TIMEOUT", "5"))
    db_lock_wait_timeout: int = int(os.getenv("WAREHOUSE_DB_LOCK_WAIT_TIMEOUT", "2"))
    # The service has no authentication, so it only listens locally by default.
    grpc_host: str = os.getenv("WAREHOUSE_GRPC_HOST", "127.0.0.1")
    grpc_port: int = int(os.getenv("WAREHOUSE_GRPC_PORT", "50051"))
    max_workers: int = int(os.getenv("WAREHOUSE_MAX_WORKERS", "10"))


settings = Settings()

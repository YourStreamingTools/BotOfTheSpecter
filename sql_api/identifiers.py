"""Safe SQL identifier validation (no raw SQL from clients)."""
from __future__ import annotations

import re

_IDENT = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")
_MAX_IDENT = 64

# Ops allowed in structured filters (values always bound as parameters).
ALLOWED_OPS = frozenset({"eq", "ne", "lt", "lte", "gt", "gte", "like", "in", "is_null", "is_not_null"})

_OP_SQL = {
    "eq": "=",
    "ne": "!=",
    "lt": "<",
    "lte": "<=",
    "gt": ">",
    "gte": ">=",
    "like": "LIKE",
}


def validate_ident(name: str, *, kind: str = "identifier") -> str:
    if not name or not isinstance(name, str):
        raise ValueError(f"Invalid {kind}")
    name = name.strip()
    if len(name) > _MAX_IDENT or not _IDENT.fullmatch(name):
        raise ValueError(f"Invalid {kind}: {name!r}")
    return name


def quote_ident(name: str) -> str:
    """Backtick-quote a validated identifier."""
    safe = validate_ident(name)
    return f"`{safe}`"

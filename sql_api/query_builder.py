"""Build parameterized SELECT/INSERT/UPDATE/DELETE from structured filters."""
from __future__ import annotations

from typing import Any

from identifiers import ALLOWED_OPS, _OP_SQL, quote_ident, validate_ident


class FilterError(ValueError):
    pass


def _one_filter(filt: dict[str, Any]) -> tuple[str, list[Any]]:
    col = validate_ident(str(filt.get("column") or ""), kind="column")
    op = str(filt.get("op") or "eq").strip().lower()
    if op not in ALLOWED_OPS:
        raise FilterError(f"Unsupported op: {op}")
    qcol = quote_ident(col)

    if op == "is_null":
        return f"{qcol} IS NULL", []
    if op == "is_not_null":
        return f"{qcol} IS NOT NULL", []

    value = filt.get("value")
    if op == "in":
        if not isinstance(value, (list, tuple)) or len(value) == 0:
            raise FilterError("'in' requires a non-empty list value")
        if len(value) > 100:
            raise FilterError("'in' list too large (max 100)")
        placeholders = ", ".join(["%s"] * len(value))
        return f"{qcol} IN ({placeholders})", list(value)

    return f"{qcol} {_OP_SQL[op]} %s", [value]


def build_where(filters: list[dict[str, Any]] | None) -> tuple[str, list[Any]]:
    if not filters:
        return "", []
    if len(filters) > 20:
        raise FilterError("Too many filters (max 20)")
    parts: list[str] = []
    params: list[Any] = []
    for f in filters:
        if not isinstance(f, dict):
            raise FilterError("Each filter must be an object")
        clause, p = _one_filter(f)
        parts.append(clause)
        params.extend(p)
    return " WHERE " + " AND ".join(parts), params


def build_select(
    table: str,
    *,
    columns: list[str] | None = None,
    filters: list[dict[str, Any]] | None = None,
    order_by: str | None = None,
    order_dir: str = "asc",
    limit: int = 100,
    offset: int = 0,
) -> tuple[str, list[Any]]:
    table_q = quote_ident(table)
    if columns:
        cols = ", ".join(quote_ident(c) for c in columns)
    else:
        cols = "*"
    where_sql, params = build_where(filters)
    sql = f"SELECT {cols} FROM {table_q}{where_sql}"
    if order_by:
        direction = "DESC" if str(order_dir).lower() == "desc" else "ASC"
        sql += f" ORDER BY {quote_ident(order_by)} {direction}"
    limit = max(1, min(int(limit), 500))
    offset = max(0, min(int(offset), 100_000))
    sql += " LIMIT %s OFFSET %s"
    params.extend([limit, offset])
    return sql, params


def build_insert(table: str, data: dict[str, Any]) -> tuple[str, list[Any]]:
    if not data:
        raise FilterError("Insert data must not be empty")
    if len(data) > 50:
        raise FilterError("Too many columns (max 50)")
    cols = [validate_ident(k, kind="column") for k in data.keys()]
    col_sql = ", ".join(quote_ident(c) for c in cols)
    placeholders = ", ".join(["%s"] * len(cols))
    sql = f"INSERT INTO {quote_ident(table)} ({col_sql}) VALUES ({placeholders})"
    return sql, [data[c] for c in cols]


def build_update(
    table: str,
    data: dict[str, Any],
    filters: list[dict[str, Any]] | None,
) -> tuple[str, list[Any]]:
    if not data:
        raise FilterError("Update data must not be empty")
    if not filters:
        raise FilterError("UPDATE requires at least one filter (refuse full-table update)")
    if len(data) > 50:
        raise FilterError("Too many columns (max 50)")
    sets = []
    params: list[Any] = []
    for k, v in data.items():
        col = validate_ident(k, kind="column")
        sets.append(f"{quote_ident(col)} = %s")
        params.append(v)
    where_sql, where_params = build_where(filters)
    sql = f"UPDATE {quote_ident(table)} SET {', '.join(sets)}{where_sql}"
    params.extend(where_params)
    return sql, params


def build_delete(
    table: str,
    filters: list[dict[str, Any]] | None,
) -> tuple[str, list[Any]]:
    if not filters:
        raise FilterError("DELETE requires at least one filter (refuse full-table delete)")
    where_sql, params = build_where(filters)
    sql = f"DELETE FROM {quote_ident(table)}{where_sql}"
    return sql, params

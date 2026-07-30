"""Unit tests for structured query builder (no MySQL required)."""
from __future__ import annotations

import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from identifiers import validate_ident  # noqa: E402
from query_builder import (  # noqa: E402
    FilterError,
    build_delete,
    build_insert,
    build_select,
    build_update,
)


def test_validate_ident_ok():
    assert validate_ident("custom_commands") == "custom_commands"


def test_validate_ident_rejects_injection():
    with pytest.raises(ValueError):
        validate_ident("users; drop table")
    with pytest.raises(ValueError):
        validate_ident("a.b")


def test_build_select_filter():
    sql, params = build_select(
        "custom_user_commands",
        filters=[{"column": "user_id", "op": "eq", "value": "bob"}],
        order_by="command",
        limit=10,
        offset=0,
    )
    assert "FROM `custom_user_commands`" in sql
    assert "`user_id` = %s" in sql
    assert params[0] == "bob"
    assert params[-2:] == [10, 0]


def test_build_insert():
    sql, params = build_insert("t", {"command": "snack", "options": "[]"})
    assert sql.startswith("INSERT INTO `t`")
    assert params == ["snack", "[]"]


def test_update_requires_filter():
    with pytest.raises(FilterError):
        build_update("t", {"x": 1}, None)


def test_delete_requires_filter():
    with pytest.raises(FilterError):
        build_delete("t", [])


def test_update_ok():
    sql, params = build_update(
        "t",
        {"options": "[]"},
        [{"column": "command", "op": "eq", "value": "snack"}],
    )
    assert "UPDATE `t` SET" in sql
    assert params == ["[]", "snack"]

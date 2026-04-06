from datetime import datetime, timedelta, timezone
from typing import Any

from jose import JWTError, jwt

from app.core.settings import Settings


def create_access_token(
    subject: str,
    settings: Settings,
    extra_claims: dict[str, Any] | None = None,
) -> str:
    expire = datetime.now(timezone.utc) + timedelta(minutes=settings.jwt_expire_minutes)
    to_encode: dict[str, Any] = {"sub": subject, "exp": expire}
    if extra_claims:
        to_encode.update(extra_claims)
    return jwt.encode(to_encode, settings.jwt_secret_key, algorithm=settings.jwt_algorithm)


def decode_token(token: str, settings: Settings) -> dict[str, Any]:
    return jwt.decode(token, settings.jwt_secret_key, algorithms=[settings.jwt_algorithm])


def verify_bearer_token(token: str, settings: Settings) -> dict[str, Any]:
    try:
        return decode_token(token, settings)
    except JWTError as exc:
        raise ValueError("Token inválido o expirado") from exc

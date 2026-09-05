[English](development.md) | Русский

# Разработка

В репозитории есть собственное окружение и демо-приложение, поэтому пакет можно
не только читать, но и запустить:

```bash
make setup
```

После этого демо отвечает на `http://localhost:48081` (порт — `APP_PORT` в `.env`).
Что там показано и что стоит попробовать руками — [demo/README.ru.md](../demo/README.ru.md).

## Что поднимается

| Контейнер | Роль |
|---|---|
| `scl-nginx` | единственный опубликованный вход; проксирует в пул `http` |
| `scl-php` | только CLI: composer, artisan, phpunit, анализаторы. php-fpm здесь нет — HTTP отдаёт сам SConcur |
| `scl-workers` | supervisor, под ним мастер SConcur с группами `http`, `rabbitmq` и `tasks` |
| `scl-mysql` | MySQL 8.4, данные в `tmpfs` — стираются при пересоздании контейнера |
| `scl-rabbitmq` | RabbitMQ 4.1 с панелью, тоже в `tmpfs` |

Расширение `sconcur.so` вшито в образ: `docker/php/Dockerfile` читает версию
`sconcur/sconcur` из `composer.lock` и качает соответствующий ассет релиза. Поэтому
`composer.lock` лежит в репозитории — без него на свежем клоне нечего пинить. Версия
библиотеки закреплена точно, а не кареткой: `.so` и PHP-сторона пересекают границу
протокола, которая меняется вместе с версией.

`composer.lock` собирается одноразовым контейнером (`make composer-lock`), не образом
проекта: образ не построится без лока, который он же и читает. Целевую платформу
резолвинга задаёт `config.platform` в `composer.json`.

## Команды

```bash
make up / make stop / make restart    # окружение
make demo-art c=...                   # artisan демо-приложения
make workers-art c=...                # тот же artisan внутри контейнера воркеров
make queues-declare                   # объявить очереди, которые читает пул консьюмеров
make sconcur-status                   # статус мастера: группы и воркеры
make sconcur-reload                   # rolling restart пулов, мастер остаётся жив
make tasks-stop / make tasks-restart  # управление пулом задач из другого контейнера
make ws-check c=50                    # ws-пул от начала до конца, с кодом возврата
make check                            # cs-fixer, phpstan, тесты
make test c=--filter=DsnTest          # один тест
```

Тестам нужно поднятое окружение: они грузят `sconcur.so`, а интеграционные ходят в
живые MySQL и RabbitMQ.

## Тесты и демо — разные приложения

`workbench/` живёт под `orchestra/testbench` и принадлежит тестам. `demo/` — отдельное
минимальное приложение со своим `bootstrap/app.php`, потому что ему нужен
`AsyncApplication`, а testbench собирает `Illuminate\Foundation\Application` сам.
Своего `composer.json` у демо нет: `demo/vendor` — симлинк на корневой `vendor`, классы
автозагружаются через `autoload-dev` корня. Один install, один лок, и пакет с
приложением, которое его демонстрирует, не могут разъехаться.

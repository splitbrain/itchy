CREATE TABLE games
(
    gameid    INT   NOT NULL PRIMARY KEY,
    key       INT   NOT NULL,
    title     TEXT  NOT NULL,
    short     TEXT  NOT NULL DEFAULT '',
    long      TEXT  NOT NULL DEFAULT '',
    url       TEXT  NOT NULL,
    picurl    TEXT  NOT NULL,
    bought    DATE  NOT NULL,
    published DATE  NOT NULL,
    author    TEXT  NOT NULL,
    rating    FLOAT NOT NULL DEFAULT 0.0,
    rates     INT   NOT NULL DEFAULT 0
);

CREATE TABLE traits
(
    gameid INT  NOT NULL,
    trait  TEXT NOT NULL,
    PRIMARY KEY (gameid, trait),
    FOREIGN KEY (gameid) REFERENCES games (gameid)
);

CREATE TABLE files
(
    fileid  INT  NOT NULL PRIMARY KEY,
    gameid  INT  NOT NULL,
    name    TEXT NOT NULL,
    size    INT  NOT NULL,
    updated DATE NOT NULL,
    FOREIGN KEY (gameid) REFERENCES games (gameid)
);

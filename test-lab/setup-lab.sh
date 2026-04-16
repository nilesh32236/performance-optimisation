#!/bin/bash

# Configuration
LAB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MASTER_PORT=6381
REPLICA_PORT=6382
SENTINEL_1_PORT=26381
SENTINEL_2_PORT=26382
MASTER_NAME="mymaster"

# Create config directory
mkdir -p "$LAB_DIR/data"

cat <<EOF > "$LAB_DIR/master.conf"
port $MASTER_PORT
dir "$LAB_DIR/data"
dbfilename master.rdb
EOF

cat <<EOF > "$LAB_DIR/replica.conf"
port $REPLICA_PORT
dir "$LAB_DIR/data"
dbfilename replica.rdb
replicaof 127.0.0.1 $MASTER_PORT
EOF

cat <<EOF > "$LAB_DIR/sentinel_1.conf"
port $SENTINEL_1_PORT
dir "$LAB_DIR/data"
sentinel monitor $MASTER_NAME 127.0.0.1 $MASTER_PORT 1
sentinel down-after-milliseconds $MASTER_NAME 5000
sentinel failover-timeout $MASTER_NAME 60000
EOF

cat <<EOF > "$LAB_DIR/sentinel_2.conf"
port $SENTINEL_2_PORT
dir "$LAB_DIR/data"
sentinel monitor $MASTER_NAME 127.0.0.1 $MASTER_PORT 1
sentinel down-after-milliseconds $MASTER_NAME 5000
sentinel failover-timeout $MASTER_NAME 60000
EOF

start_lab() {
    echo "Starting Redis Master on port $MASTER_PORT..."
    redis-server "$LAB_DIR/master.conf" --daemonize yes
    
    echo "Starting Redis Replica on port $REPLICA_PORT..."
    redis-server "$LAB_DIR/replica.conf" --daemonize yes
    
    echo "Starting Redis Sentinel 1 on port $SENTINEL_1_PORT..."
    redis-sentinel "$LAB_DIR/sentinel_1.conf" --daemonize yes

    echo "Starting Redis Sentinel 2 on port $SENTINEL_2_PORT..."
    redis-sentinel "$LAB_DIR/sentinel_2.conf" --daemonize yes
    
    echo "Lab started!"
    echo "Sentinel Nodes:"
    echo "  127.0.0.1:$SENTINEL_1_PORT"
    echo "  127.0.0.1:$SENTINEL_2_PORT"
    echo "Master Name: $MASTER_NAME"
}

stop_lab() {
    echo "Stopping Redis services..."
    pkill -f "$LAB_DIR/master.conf"
    pkill -f "$LAB_DIR/replica.conf"
    pkill -f "$LAB_DIR/sentinel_1.conf"
    pkill -f "$LAB_DIR/sentinel_2.conf"
    echo "Lab stopped."
}

status_lab() {
    echo "Checking ports..."
    ss -tlnp | grep -E "$MASTER_PORT|$REPLICA_PORT|$SENTINEL_1_PORT|$SENTINEL_2_PORT"
}


case "$1" in
    start)
        start_lab
        ;;
    stop)
        stop_lab
        ;;
    status)
        status_lab
        ;;
    *)
        echo "Usage: $0 {start|stop|status}"
        exit 1
esac
